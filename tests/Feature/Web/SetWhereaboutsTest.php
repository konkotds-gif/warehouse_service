<?php

namespace Tests\Feature\Web;

use App\Domain\Transfer\TransferService;
use App\Domain\Warehouse\PlacementService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Карточка договора показывает, где комплект лежит сейчас. */
class SetWhereaboutsTest extends TestCase
{
    private function where(string $contractId): array
    {
        $out = null;
        $this->get("/contracts/{$contractId}")->assertInertia(function (Assert $p) use (&$out) {
            $p->component('Contracts/Show');
            $out = $p->toArray()['props']['contract']['sets'][0]['whereabouts'];
        });

        return $out;
    }

    public function test_whereabouts_follow_the_set_from_store_to_cell(): void
    {
        $store = $this->store('М01', 'Богатырь');
        $wh = $this->warehouse('02');
        $contract = $this->accept($store);
        $set = $this->firstSet($contract);
        $this->actingAs($this->user());
        $transfers = app(TransferService::class);

        $w = $this->where($contract->id);
        $this->assertSame('buffer', $w['kind']);
        $this->assertSame('Богатырь (М01)', $w['place']);
        $this->assertStringContainsString('Буфер магазина', $w['detail']);

        $t = $transfers->create($store, $wh, [$set->id]);
        $w = $this->where($contract->id);
        $this->assertStringContainsString("перемещение {$t->number} → Склад 02 (02), ждёт погрузки", $w['pending']);

        $transfers->scanOut($t, $set->qr_code, $set->qty);
        $transfers->ship($t, 'Иванов');
        $w = $this->where($contract->id);
        $this->assertSame('transit', $w['kind']);
        $this->assertSame('В пути: М01 → Склад 02 (02)', $w['place']);
        $this->assertStringContainsString("Перемещение {$t->number}", $w['detail']);
        $this->assertStringContainsString('водитель Иванов', $w['detail']);
        $this->assertNull($w['pending']);

        $transfers->scanIn($t, $set->qr_code);
        $w = $this->where($contract->id);
        $this->assertSame('buffer', $w['kind']);
        $this->assertStringContainsString('ждёт размещения', $w['detail']);

        app(PlacementService::class)->place($set->fresh(), '02_01_02');
        $w = $this->where($contract->id);
        $this->assertSame('cell', $w['kind']);
        $this->assertSame('Склад 02 (02)', $w['place']);
        $this->assertSame('Ряд 01 · ячейка 02', $w['detail']);
        $this->assertSame('02_01_02', $w['address']);
    }
}
