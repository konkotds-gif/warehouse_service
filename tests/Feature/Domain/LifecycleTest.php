<?php

namespace Tests\Feature\Domain;

use App\Domain\Storage\ContractStatus;
use App\Domain\Storage\GarageSetState;
use App\Domain\Storage\IssueService;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\DomainError;
use App\Domain\Transfer\TransferService;
use App\Domain\Users\Role;
use App\Domain\Warehouse\PlacementService;
use App\Domain\Warehouse\TaskType;
use App\Domain\Warehouse\TransferStatus;
use App\Models\Movement;
use App\Models\OutboxEvent;
use App\Models\Task;
use App\Models\WheelSet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Полный цикл: приём → склад → размещение → заказ → магазин выдачи → выдача.
 */
class LifecycleTest extends TestCase
{
    private function transfers(): TransferService
    {
        return app(TransferService::class);
    }

    private function shipAndReceive(WheelSet $set, $from, $to): void
    {
        $t = $this->transfers()->create($from, $to, [$set->id]);
        $this->transfers()->scanOut($t, $set->qr_code, $set->qty);
        $this->transfers()->ship($t);
        $this->assertSame(SetStatus::InTransit, $set->fresh()->status);
        $this->assertSame('TR-'.$t->number, $set->fresh()->currentAddress());
        $this->transfers()->scanIn($t, $set->qr_code);
        $this->assertSame(TransferStatus::Received, $t->fresh()->status, 'всё принято — перемещение закрыто автоматически');
    }

    public function test_full_lifecycle(): void
    {
        $store1 = $this->store('М01');
        $store5 = $this->store('М05', 'Магазин 5');
        $wh = $this->warehouse('02');
        $contract = $this->accept($store1);
        $set = $this->firstSet($contract);

        // 1. Магазин → склад
        $this->shipAndReceive($set, $store1, $wh);
        $set->refresh();
        $this->assertSame(SetStatus::Received, $set->status);
        $this->assertSame(1, Task::where('type', TaskType::Place->value)->where('status', 'open')->count());

        // 2. Размещение по предложенной ячейке
        $placement = app(PlacementService::class);
        $suggested = $placement->suggest($set);
        $this->assertSame('02_01_01', $suggested->address);
        $placement->place($set, '02_01_01', $suggested->address);
        $set->refresh();
        $this->assertSame(SetStatus::Placed, $set->status);
        $this->assertSame('02_01_01', $set->currentAddress());
        $this->assertSame(0, Task::where('type', TaskType::Place->value)->where('status', 'open')->count());

        // 3. Заказ к выдаче в другом магазине → задание «отобрать»
        $issue = app(IssueService::class);
        $issue->order($set, $store5, now()->addDays(3));
        $set->refresh();
        $this->assertSame(SetStatus::Ordered, $set->status);
        $this->assertSame('02_01_01', $set->currentAddress(), 'до отгрузки комплект лежит в ячейке');
        $pick = Task::where('type', TaskType::Pick->value)->first();
        $this->assertSame($wh->id, $pick->location_id);
        $this->assertSame(now()->addDays(2)->toDateString(), $pick->due_date->toDateString(), 'срок D−1');

        // 4. Склад → магазин выдачи: по приёмке «Готов к выдаче», SMS
        app()->instance('sms.sent', collect());
        $candidates = $this->transfers()->issueCandidates($wh, $store5);
        $this->assertCount(1, $candidates);
        $this->shipAndReceive($set, $wh, $store5);
        $set->refresh();
        $this->assertSame(SetStatus::Ready, $set->status);
        $this->assertNull($set->current_cell_id, 'ячейка освобождена');
        $this->assertSame('done', Task::find($pick->id)->status->value);
        $this->assertCount(1, app('sms.sent'));

        // 5. Выдача: проверка долга и бирки
        $seller = $this->user(Role::Seller, $store5);
        $contract->update(['debt' => 500, 'debt_updated_at' => now()]);
        try {
            $issue->issue($set, $set->qr_code, 4, $seller);
            $this->fail('выдача при долге должна блокироваться');
        } catch (DomainError $e) {
            $this->assertStringContainsString('долг', $e->getMessage());
        }
        $contract->update(['debt' => 0]);

        $other = $this->accept($store1, ['client' => ['full_name' => 'Петров', 'phone' => '+79990000000'], 'vehicle' => ['plate' => 'В777ВВ86']]);
        try {
            $issue->issue($set, $other->number, 4, $seller);
            $this->fail('чужая бирка не должна проходить');
        } catch (DomainError $e) {
            $this->assertStringContainsString('Бирка не от этого комплекта', $e->getMessage());
        }

        $issue->issue($set, $set->qr_code, 4, $seller);
        $set->refresh();
        $this->assertSame(SetStatus::Issued, $set->status);
        $this->assertSame('ISSUED', $set->currentAddress());
        $this->assertSame(ContractStatus::Closed, $contract->fresh()->status);
        $this->assertSame(GarageSetState::OnVehicle, $set->garageSet->state);
        $this->assertTrue(OutboxEvent::where('event_type', 'set.issued')->exists());
        $this->assertTrue(OutboxEvent::where('event_type', 'contract.closed')->exists());
        $this->assertSame(2, OutboxEvent::where('event_type', 'transfer.completed')->count());

        $actions = Movement::where('wheel_set_id', $set->id)->orderBy('id')->pluck('action')->all();
        // SMS «готовы к выдаче» тоже пишется в историю движений
        $this->assertSame(['accept', 'ship', 'receive', 'place', 'order', 'ship', 'ready', 'sms_sent', 'issue'], $actions);
    }

    public function test_cell_capacity_and_other_warehouse_are_enforced(): void
    {
        $store = $this->store();
        $wh = $this->warehouse('02');
        $wh3 = $this->warehouse('03');
        $a = $this->firstSet($this->accept($store));
        $b = $this->firstSet($this->accept($store, ['client' => ['full_name' => 'Б', 'phone' => '+79990000001'], 'vehicle' => ['plate' => 'В111ВВ86']]));
        foreach ([$a, $b] as $s) {
            $this->shipAndReceive($s, $store, $wh);
        }
        $placement = app(PlacementService::class);
        $placement->place($a->fresh(), '02_01_01');

        try {
            $placement->place($b->fresh(), '02_01_01');
            $this->fail('ячейка занята');
        } catch (DomainError $e) {
            $this->assertSame(409, $e->status);
        }
        try {
            $placement->place($b->fresh(), '03_01_01');
            $this->fail('ячейка на другом складе');
        } catch (DomainError $e) {
            $this->assertStringContainsString('другом складе', $e->getMessage());
        }
        $this->assertSame('02_01_02', $placement->suggest($b->fresh())->address, 'предлагается следующая свободная');

        // Перекладка размещённого комплекта
        $placement->place($a->fresh(), '02_02_01');
        $this->assertSame('02_02_01', $a->fresh()->currentAddress());
        $this->assertSame('move', Movement::where('wheel_set_id', $a->id)->orderByDesc('id')->value('action'));
    }

    public function test_capacity_two_allows_two_sets(): void
    {
        $store = $this->store();
        $wh = $this->warehouse('02', 1, 1, 2);
        $sets = [];
        foreach (['+79990000011', '+79990000012', '+79990000013'] as $i => $phone) {
            $s = $this->firstSet($this->accept($store, ['client' => ['full_name' => "K{$i}", 'phone' => $phone], 'vehicle' => ['plate' => "Е{$i}00ЕЕ86"]]));
            $this->shipAndReceive($s, $store, $wh);
            $sets[] = $s->fresh();
        }
        $placement = app(PlacementService::class);
        $placement->place($sets[0], '02_01_01');
        $placement->place($sets[1], '02_01_01');
        $this->expectException(DomainError::class);
        $placement->place($sets[2], '02_01_01');
    }

    public function test_transfer_discrepancy_and_late_scan(): void
    {
        $store = $this->store();
        $wh = $this->warehouse();
        $a = $this->firstSet($this->accept($store));
        $b = $this->firstSet($this->accept($store, ['client' => ['full_name' => 'Б', 'phone' => '+79990000001'], 'vehicle' => ['plate' => 'В111ВВ86']]));

        $this->assertCount(2, $this->transfers()->bufferCandidates($store));
        $t = $this->transfers()->create($store, $wh, [$a->id, $b->id]);
        $this->assertCount(0, $this->transfers()->bufferCandidates($store), 'включённые в перемещение не предлагаются');

        $this->transfers()->scanOut($t, $a->qr_code, 3); // расхождение по количеству
        $this->assertSame(1, Task::where('type', TaskType::Discrepancy->value)->count());
        $this->transfers()->scanOut($t, $b->qr_code);
        $this->transfers()->ship($t);

        $this->transfers()->scanIn($t, $a->qr_code);
        $this->transfers()->complete($t);
        $this->assertSame(TransferStatus::Discrepancy, $t->fresh()->status);
        $this->assertSame(SetStatus::InTransit, $b->fresh()->status);
        $this->assertSame(2, Task::where('type', TaskType::Discrepancy->value)->count());

        // Нашёлся позже
        $this->transfers()->scanIn($t, $b->qr_code);
        $this->assertSame(TransferStatus::Received, $t->fresh()->status);
        $this->assertSame(SetStatus::Received, $b->fresh()->status);
    }

    public function test_unscanned_lines_are_dropped_on_ship(): void
    {
        $store = $this->store();
        $wh = $this->warehouse();
        $a = $this->firstSet($this->accept($store));
        $b = $this->firstSet($this->accept($store, ['client' => ['full_name' => 'Б', 'phone' => '+79990000001'], 'vehicle' => ['plate' => 'В111ВВ86']]));
        $t = $this->transfers()->create($store, $wh, [$a->id, $b->id]);
        $this->transfers()->scanOut($t, $a->qr_code);
        $this->transfers()->ship($t);
        $this->assertSame(1, $t->lines()->count());
        $this->assertSame(SetStatus::Accepted, $b->fresh()->status);
        $this->assertSame('М01', $b->fresh()->currentAddress());
    }

    public function test_cannot_scan_foreign_set_into_transfer(): void
    {
        $store = $this->store();
        $wh = $this->warehouse();
        $a = $this->firstSet($this->accept($store));
        $b = $this->firstSet($this->accept($store, ['client' => ['full_name' => 'Б', 'phone' => '+79990000001'], 'vehicle' => ['plate' => 'В111ВВ86']]));
        $t = $this->transfers()->create($store, $wh, [$a->id]);
        $this->expectException(DomainError::class);
        $this->transfers()->scanOut($t, $b->qr_code);
    }

    public function test_order_at_same_store_is_immediately_ready_and_cancel_returns(): void
    {
        $store = $this->store();
        $set = $this->firstSet($this->accept($store));
        $issue = app(IssueService::class);
        $issue->order($set, $store, now());
        $this->assertSame(SetStatus::Ready, $set->fresh()->status);

        $wh = $this->warehouse();
        $set2 = $this->firstSet($this->accept($store, ['client' => ['full_name' => 'Б', 'phone' => '+79990000001'], 'vehicle' => ['plate' => 'В111ВВ86']]));
        $this->shipAndReceive($set2, $store, $wh);
        app(PlacementService::class)->place($set2->fresh(), '02_01_02');
        $issue->order($set2->fresh(), $store, now()->addDay());
        $issue->cancelOrder($set2->fresh());
        $this->assertSame(SetStatus::Placed, $set2->fresh()->status);
        $this->assertSame('cancelled', Task::where('wheel_set_id', $set2->id)->where('type', 'pick')->first()->status->value);
    }

    public function test_issue_requires_ready_status(): void
    {
        $store = $this->store();
        $set = $this->firstSet($this->accept($store));
        $this->expectException(DomainError::class);
        app(IssueService::class)->issue($set, $set->qr_code);
    }

    public function test_movement_journal_is_append_only_in_database(): void
    {
        $set = $this->firstSet($this->accept($this->store()));
        $id = Movement::where('wheel_set_id', $set->id)->value('id');

        foreach ([
            fn () => DB::table('movements')->where('id', $id)->update(['note' => 'x']),
            fn () => DB::table('movements')->where('id', $id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('триггер должен запретить изменение журнала');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }

    public function test_placed_set_must_have_cell_check_constraint(): void
    {
        $set = $this->firstSet($this->accept($this->store()));
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('wheel_sets')->where('id', $set->id)->update(['status' => 'placed', 'current_cell_id' => null]));
    }
}
