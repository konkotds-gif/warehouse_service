<?php

namespace Tests\Feature\Domain;

use App\Domain\Storage\IssueService;
use App\Domain\Storage\SetStatus;
use App\Domain\Storage\SetWhereabouts;
use App\Domain\Transfer\TransferService;
use App\Domain\Users\Role;
use App\Domain\Warehouse\Indicators;
use App\Domain\Warehouse\PlacementService;
use App\Domain\Warehouse\TaskType;
use App\Domain\Warehouse\TransferStatus;
use App\Models\Location;
use App\Models\Task;
use App\Models\Transfer;
use App\Models\WheelSet;
use Tests\TestCase;

/** Склад и магазин в одном здании: заказ к выдаче проводится сразу, без водителя; кладовщику — «Выдать клиенту». */
class SameBuildingIssueTest extends TestCase
{
    private function transfers(): TransferService
    {
        return app(TransferService::class);
    }

    private function placed(Location $store, Location $wh, string $cell, int $n = 1): WheelSet
    {
        $set = $this->firstSet($this->accept($store, ['client' => ['full_name' => "К{$n}", 'phone' => '+7999000001'.$n], 'vehicle' => ['plate' => "А{$n}00АА86"]]));
        $t = $this->transfers()->create($store, $wh, [$set->id]);
        $this->transfers()->scanOut($t, $set->qr_code, $set->qty);
        $this->transfers()->ship($t);
        $this->transfers()->scanIn($t, $set->qr_code);
        app(PlacementService::class)->place($set->fresh(), $cell);

        return $set->fresh();
    }

    /** @return array{0: Location, 1: Location} */
    private function building(): array
    {
        $store = $this->store('М01');
        $wh = $this->warehouse('02');
        $store->update(['building' => 'B1']);
        $wh->update(['building' => 'B1']);

        return [$store->fresh(), $wh->fresh()];
    }

    public function test_order_in_same_building_is_posted_at_once_and_keeper_gets_issue_task(): void
    {
        [$store, $wh] = $this->building();
        $set = $this->placed($store, $wh, '02_01_02');
        $keeper = $this->user(Role::Storekeeper, $wh);

        $set = app(IssueService::class)->order($set, $store, now()->addDays(2));

        $this->assertSame(SetStatus::Ready, $set->status, 'сразу готов к выдаче — водитель не нужен');
        $this->assertSame($store->id, $set->current_location_id);
        $this->assertNull($set->current_cell_id);

        $t = Transfer::where('purpose', 'issue')->sole();
        $this->assertTrue($t->same_building);
        $this->assertSame(TransferStatus::Received, $t->status, 'перемещение проведено сразу');
        $this->assertNull($t->trip_id);
        $this->assertSame(0, Transfer::where('status', 'created')->count(), 'в пул «Перевозки» ничего не попало');
        $this->assertSame(0, Task::where('type', TaskType::Pick->value)->count(), 'задания «отобрать» для водителя нет');

        $task = Task::where('type', TaskType::IssueToClient->value)->sole();
        $this->assertSame($wh->id, $task->location_id);
        $this->assertSame('02_01_02', $task->payload['shelf']);
        $this->assertStringContainsString('снять с полки 02_01_02', $task->title);
        $this->assertSame(now()->addDays(2)->toDateString(), $task->due_date->toDateString());

        // Индикатор кладовщика
        $ind = collect(app(Indicators::class)->for($keeper))->keyBy('key');
        $this->assertSame(1, $ind['to_client']['count']);

        // «Где лежит сейчас»: готов в магазине, но колёса ещё на полке
        $w = SetWhereabouts::forSets(WheelSet::whereKey($set->id)->get())[$set->id];
        $this->assertTrue($w['building']['on_shelf']);
        $this->assertStringContainsString('02_01_02', $w['detail']);

        // Кладовщик снял с полки — задание закрыто
        $task->update(['status' => 'done', 'completed_at' => now()]);
        $w = SetWhereabouts::forSets(WheelSet::whereKey($set->id)->get())[$set->id];
        $this->assertFalse($w['building']['on_shelf']);

        // Выдача продавцом — обычная, задание закрывается
        $seller = $this->user(Role::Seller, $store);
        app(IssueService::class)->issue($set->fresh(), $set->qr_code, null, $seller);
        $this->assertSame(SetStatus::Issued, $set->fresh()->status);
    }

    public function test_other_store_still_goes_through_driver(): void
    {
        [$store, $wh] = $this->building();
        $other = $this->store('М05', 'Магазин 5');
        $set = $this->placed($store, $wh, '02_01_01');

        $set = app(IssueService::class)->order($set, $other, now()->addDay());

        $this->assertSame(SetStatus::Ordered, $set->status);
        $t = Transfer::where('purpose', 'issue')->sole();
        $this->assertFalse($t->same_building);
        $this->assertSame(TransferStatus::Created, $t->status);
        $this->assertSame(1, Task::where('type', TaskType::Pick->value)->count());
        $this->assertSame(0, Task::where('type', TaskType::IssueToClient->value)->count());
    }

    public function test_issue_closes_task_and_cancel_returns_to_the_same_cell(): void
    {
        [$store, $wh] = $this->building();
        $a = $this->placed($store, $wh, '02_01_03', 1);
        $b = $this->placed($store, $wh, '02_02_01', 2);
        $issue = app(IssueService::class);

        // Выдача закрывает задание кладовщика
        $issue->order($a, $store, now());
        $issue->issue($a->fresh(), $a->qr_code);
        $this->assertSame('done', Task::where('wheel_set_id', $a->id)->where('type', TaskType::IssueToClient->value)->sole()->status->value);

        // Отмена, пока колёса на полке: снова «Размещён» в своей ячейке, задание отменено
        $issue->order($b, $store, now()->addDay());
        $b = $issue->cancelOrder($b->fresh());
        $this->assertSame(SetStatus::Placed, $b->status);
        $this->assertSame('02_02_01', $b->currentAddress());
        $this->assertNull($b->issue_store_id);
        $this->assertSame('cancelled', Task::where('wheel_set_id', $b->id)->where('type', TaskType::IssueToClient->value)->sole()->status->value);
        $this->assertSame(2, Transfer::where('same_building', true)->whereHas('lines', fn ($q) => $q->where('wheel_set_id', $b->id))->count());

        // Отмена после снятия с полки: на склад без ячейки + задание «Разместить»
        $issue->order($b, $store, now()->addDay());
        Task::where('wheel_set_id', $b->id)->where('type', TaskType::IssueToClient->value)->where('status', 'open')
            ->update(['status' => 'done', 'completed_at' => now()]);
        $b = $issue->cancelOrder($b->fresh());
        $this->assertSame(SetStatus::Received, $b->status);
        $this->assertSame($wh->id, $b->current_location_id);
        $this->assertSame(1, Task::where('wheel_set_id', $b->id)->where('type', TaskType::Place->value)->where('status', 'open')->count());
    }

    public function test_location_settings_link_store_and_warehouse(): void
    {
        $store = $this->store('М01');
        $wh = $this->warehouse('02');
        $admin = $this->user();

        $this->actingAs($admin)->put("/locations/{$store->id}", [
            'code' => 'М01', 'name' => 'Магазин 1', 'type' => 'store', 'is_active' => true, 'same_building' => [$wh->id],
        ])->assertSessionHasNoErrors();
        $this->assertTrue($store->fresh()->sameBuildingAs($wh->fresh()));

        // Снять отметку — точки снова раздельные
        $this->actingAs($admin)->put("/locations/{$store->id}", [
            'code' => 'М01', 'name' => 'Магазин 1', 'type' => 'store', 'is_active' => true, 'same_building' => [],
        ])->assertSessionHasNoErrors();
        $this->assertNull($store->fresh()->building);
        $this->assertNull($wh->fresh()->building);
    }
}
