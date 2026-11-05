<?php

namespace Tests\Feature\Domain;

use App\Domain\Storage\IssueService;
use App\Domain\Storage\SetStatus;
use App\Domain\Transfer\TransferService;
use App\Domain\Transfer\TripService;
use App\Domain\Warehouse\PlacementService;
use App\Domain\Warehouse\TaskType;
use App\Domain\Warehouse\TransferStatus;
use App\Models\Task;
use App\Models\Transfer;
use App\Models\WheelSet;
use Tests\TestCase;

/** «Заказать к выдаче» сам собирает комплекты в одно перемещение склад → магазин. */
class IssueTransferTest extends TestCase
{
    private function transfers(): TransferService
    {
        return app(TransferService::class);
    }

    /** Комплект, размещённый на складе. */
    private function placed($store, $wh, string $cell, int $n): WheelSet
    {
        $set = $this->firstSet($this->accept($store, ['client' => ['full_name' => "К{$n}", 'phone' => '+7999000001'.$n], 'vehicle' => ['plate' => "А{$n}00АА86"]]));
        $t = $this->transfers()->create($store, $wh, [$set->id]);
        $this->transfers()->scanOut($t, $set->qr_code, $set->qty);
        $this->transfers()->ship($t);
        $this->transfers()->scanIn($t, $set->qr_code);
        app(PlacementService::class)->place($set->fresh(), $cell);

        return $set->fresh();
    }

    private function picks()
    {
        return Task::where('type', TaskType::Pick->value)->where('status', 'open');
    }

    public function test_orders_to_one_store_are_merged_into_one_transfer_and_one_task(): void
    {
        $s1 = $this->store('М01');
        $s5 = $this->store('М05', 'Магазин 5');
        $wh = $this->warehouse('02');
        $a = $this->placed($s1, $wh, '02_01_01', 1);
        $b = $this->placed($s1, $wh, '02_01_02', 2);
        $c = $this->placed($s1, $wh, '02_01_03', 3);
        $issue = app(IssueService::class);

        $issue->order($a, $s1, now()->addDays(3));
        $issue->order($b, $s1, now()->addDays(2));
        $issue->order($c, $s5, now()->addDays(2));

        $toM01 = Transfer::where('purpose', 'issue')->where('to_location_id', $s1->id)->sole();
        $this->assertSame(TransferStatus::Created, $toM01->status);
        $this->assertNull($toM01->trip_id, 'перемещение ждёт в пуле «Перевозки»');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $toM01->lines()->pluck('wheel_set_id')->all());
        $this->assertSame(1, Transfer::where('purpose', 'issue')->where('to_location_id', $s5->id)->count(), 'другой магазин — своё перемещение');
        $this->assertSame(SetStatus::Ordered, $a->fresh()->status);
        $this->assertSame('02_01_01', $a->fresh()->currentAddress(), 'до погрузки комплект лежит в ячейке');

        // Одно задание «отобрать» на перемещение, срок — по самой ранней выдаче (D−1)
        $this->assertSame(2, $this->picks()->count());
        $task = $this->picks()->where('payload->transfer_id', $toM01->id)->sole();
        $this->assertNull($task->wheel_set_id);
        $this->assertSame($wh->id, $task->location_id);
        $this->assertSame(now()->addDay()->toDateString(), $task->due_date->toDateString());
        $this->assertStringContainsString('2 компл.', $task->title);
        $this->assertSame(['02_01_01', '02_01_02'], array_column($task->payload['sets'], 'address'));

        // Отмена заказа убирает комплект из перемещения и обновляет задание
        $issue->cancelOrder($b->fresh());
        $this->assertSame([$a->id], $toM01->lines()->pluck('wheel_set_id')->all());
        $this->assertStringContainsString('1 компл.', $task->fresh()->title);
        $this->assertSame(now()->addDays(2)->toDateString(), $task->fresh()->due_date->toDateString());

        // Отмена последнего — перемещение и задание отменяются
        $issue->cancelOrder($a->fresh());
        $this->assertSame(TransferStatus::Cancelled, $toM01->fresh()->status);
        $this->assertSame('cancelled', $task->fresh()->status->value);
        $this->assertSame(SetStatus::Placed, $a->fresh()->status);

        // Новый заказ после отмены — новое перемещение
        $issue->order($a->fresh(), $s1, now()->addDay());
        $this->assertSame(1, Transfer::where('purpose', 'issue')->where('to_location_id', $s1->id)->where('status', 'created')->count());
    }

    public function test_transfer_in_trip_is_not_extended_and_unloaded_sets_keep_a_task(): void
    {
        $s1 = $this->store('М01');
        $wh = $this->warehouse('02');
        $a = $this->placed($s1, $wh, '02_01_01', 1);
        $b = $this->placed($s1, $wh, '02_01_02', 2);
        $c = $this->placed($s1, $wh, '02_01_03', 3);
        $issue = app(IssueService::class);
        $trips = app(TripService::class);

        $issue->order($a, $s1, now()->addDay());
        $issue->order($b, $s1, now()->addDay());
        $first = Transfer::where('purpose', 'issue')->sole();
        $trip = $trips->create([$first->id], 'Иванов');

        // Водитель уже грузится — новый заказ идёт в новое перемещение
        $issue->order($c, $s1, now()->addDay());
        $this->assertSame(2, Transfer::where('purpose', 'issue')->where('status', 'created')->count());
        $this->assertSame(0, $first->lines()->where('wheel_set_id', $c->id)->count());

        // Погрузили только «a» — «b» выносится в новое перемещение со своим заданием
        for ($i = 0; $i < $a->qty; $i++) {
            $trips->scanOut($trip, $a->qr_code);
        }
        $res = $trips->ship($trip);
        $rest = $res['returned']->first();
        $this->assertSame([$b->id], $rest->lines()->pluck('wheel_set_id')->all());
        $this->assertSame('done', Task::where('payload->transfer_id', $first->id)->sole()->status->value);
        $this->assertSame(1, $this->picks()->where('payload->transfer_id', $rest->id)->count());
        $this->assertSame(SetStatus::InTransit, $a->fresh()->status);
    }
}
