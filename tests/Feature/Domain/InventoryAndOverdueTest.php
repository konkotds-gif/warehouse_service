<?php

namespace Tests\Feature\Domain;

use App\Domain\Storage\ContractService;
use App\Domain\Storage\ContractStatus;
use App\Domain\Storage\SetStatus;
use App\Domain\Transfer\TransferService;
use App\Domain\Warehouse\InventoryService;
use App\Domain\Warehouse\PlacementService;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\Task;
use App\Models\WheelSet;
use Tests\TestCase;

class InventoryAndOverdueTest extends TestCase
{
    /** @return array{0: Location, 1: list<WheelSet>} */
    private function placedSets(int $count): array
    {
        $store = $this->store();
        $wh = $this->warehouse('02', 1, 4);
        $sets = [];
        for ($i = 0; $i < $count; $i++) {
            $s = $this->firstSet($this->accept($store, ['client' => ['full_name' => "К{$i}", 'phone' => '+7999000001'.$i], 'vehicle' => ['plate' => "Е{$i}00ЕЕ86"]]));
            $sets[] = $s;
        }
        $t = app(TransferService::class)->create($store, $wh, array_map(fn ($s) => $s->id, $sets));
        foreach ($sets as $s) {
            app(TransferService::class)->scanOut($t, $s->qr_code);
        }
        app(TransferService::class)->ship($t);
        foreach ($sets as $i => $s) {
            app(TransferService::class)->scanIn($t, $s->qr_code);
            app(PlacementService::class)->place($s->fresh(), sprintf('02_01_%02d', $i + 1));
        }

        return [$wh, array_map(fn ($s) => $s->fresh(), $sets)];
    }

    public function test_inventory_report_and_apply(): void
    {
        [$wh, [$a, $b, $c]] = $this->placedSets(3);
        $service = app(InventoryService::class);
        $inv = $service->start($wh);
        $this->assertSame(1, Task::where('type', 'inventory')->where('status', 'open')->count());

        $service->scan($inv, '02_01_01', $a->qr_code);   // на месте
        $service->scan($inv, '02_01_02', null);          // пусто — b пропал
        $service->scan($inv, '02_01_04', $c->qr_code);   // c не на своём месте (ждали 02_01_03)
        $service->scan($inv, '02_01_03', null);

        $report = $service->report($inv->fresh());
        $this->assertSame([['qr_code' => $b->qr_code, 'expected_address' => '02_01_02']], $report['missing']);
        $this->assertSame($c->qr_code, $report['misplaced'][0]['qr_code']);
        $this->assertSame('02_01_04', $report['misplaced'][0]['found_address']);
        $this->assertSame([], $report['unscanned_cells']);

        $service->finish($inv->fresh(), apply: true);
        $this->assertSame(SetStatus::Lost, $b->fresh()->status);
        $this->assertSame('02_01_04', $c->fresh()->currentAddress());
        $this->assertSame(0, Task::where('type', 'inventory')->where('status', 'open')->count());

        // Потерянный нашёлся при размещении
        app(PlacementService::class)->place($b->fresh(), '02_01_02');
        $this->assertSame(SetStatus::Placed, $b->fresh()->status);
    }

    public function test_overdue_marking_and_payment_reopens(): void
    {
        $contract = $this->accept($this->store());
        $contract->update(['storage_until' => now()->subDay()->toDateString()]);
        $service = app(ContractService::class);

        $this->assertSame(1, $service->markOverdue());
        $this->assertSame(ContractStatus::Overdue, $contract->fresh()->status);
        $this->assertTrue(OutboxEvent::where('event_type', 'set.overdue')->exists());
        $this->assertSame(0, $service->markOverdue(), 'повторно не помечаем');

        $service->applyPayment($contract, 1200, now()->addMonths(2)->toDateString(), '11111111-1111-1111-1111-111111111111');
        $this->assertSame(ContractStatus::Open, $contract->fresh()->status);
        // идемпотентность по документу 1С
        $service->applyPayment($contract, 1200, now()->addMonths(2)->toDateString(), '11111111-1111-1111-1111-111111111111');
        $this->assertSame(1, $contract->payments()->count());
    }

    public function test_extend_contract(): void
    {
        $tariff = $this->tariff();
        $contract = $this->accept($this->store(), ['tariff_id' => $tariff->id]);
        $before = $contract->storage_until->copy();
        app(ContractService::class)->extend($contract, 6);
        $this->assertTrue($contract->fresh()->storage_until->gt($before));
        $this->assertEquals(9600, (float) $contract->fresh()->amount);
        $this->assertTrue(OutboxEvent::where('event_type', 'contract.extended')->exists());
    }
}
