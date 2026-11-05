<?php

namespace Tests\Feature\Domain;

use App\Domain\Storage\SetStatus;
use App\Domain\Support\DomainError;
use App\Domain\Transfer\TransferService;
use App\Domain\Transfer\TripService;
use App\Domain\Users\Role;
use App\Domain\Warehouse\TransferStatus;
use App\Domain\Warehouse\TripStatus;
use App\Models\Transfer;
use Tests\TestCase;

/** Пул перевозок → ведомость → погрузка по скану сразу по нескольким перемещениям. */
class TripTest extends TestCase
{
    private function transfers(): TransferService
    {
        return app(TransferService::class);
    }

    private function trips(): TripService
    {
        return app(TripService::class);
    }

    private function second($store)
    {
        return $this->firstSet($this->accept($store, ['client' => ['full_name' => 'Б', 'phone' => '+79990000001'], 'vehicle' => ['plate' => 'В111ВВ86']]));
    }

    public function test_trip_over_several_transfers_with_reconciliation(): void
    {
        $s1 = $this->store('М01');
        $s5 = $this->store('М05', 'Магазин 5');
        $wh = $this->warehouse('02');
        $a = $this->firstSet($this->accept($s1));
        $b = $this->second($s1);
        $c = $this->firstSet($this->accept($s5, ['client' => ['full_name' => 'В', 'phone' => '+79990000002'], 'vehicle' => ['plate' => 'С222СС86']]));

        // Перемещения создаются без сканирования и попадают в пул
        $t1 = $this->transfers()->create($s1, $wh, [$a->id]);
        $t2 = $this->transfers()->create($s5, $wh, [$c->id]);
        $this->transfers()->addSets($t1, [$b->id]); // второй договор в том же перемещении
        $this->assertSame(2, $t1->lines()->count());
        $this->assertSame(2, $this->trips()->pool()->count());

        $trip = $this->trips()->create([$t1->id, $t2->id], 'Иванов', 'Газель А123АА86');
        $this->assertSame('Р00001', $trip->number);
        $this->assertSame(0, $this->trips()->pool()->count());
        $this->assertSame('Иванов', $t1->fresh()->driver);

        // Скан идёт по всей ведомости, перемещение определяется само
        $this->assertSame($t1->id, $this->trips()->scanOut($trip, $a->qr_code)->transfer_id);
        $this->assertSame($t2->id, $this->trips()->scanOut($trip, $c->qr_code)->transfer_id);
        try {
            $this->trips()->scanOut($trip, $a->qr_code);
            $this->fail('повторный скан');
        } catch (DomainError $e) {
            $this->assertStringContainsString('уже погружен', $e->getMessage());
        }

        // b не погружен: уходит в новое перемещение в пул
        $result = $this->trips()->ship($trip);
        $this->assertSame(TripStatus::Shipped, $trip->fresh()->status);
        $this->assertCount(2, $result['shipped']);
        $this->assertCount(1, $result['returned']);
        $rest = $result['returned']->first();
        $this->assertSame(TransferStatus::Created, $rest->fresh()->status);
        $this->assertNull($rest->fresh()->trip_id);
        $this->assertSame([$b->id], $rest->lines()->pluck('wheel_set_id')->all());
        $this->assertSame(SetStatus::Accepted, $b->fresh()->status);
        $this->assertSame(SetStatus::InTransit, $a->fresh()->status);
        $this->assertSame(1, $t1->lines()->count());

        // Приёмка по ведомости закрывает её
        $this->trips()->scanIn($trip, $a->qr_code);
        $this->assertSame(TripStatus::Shipped, $trip->fresh()->status);
        $this->trips()->scanIn($trip, $c->qr_code);
        $this->assertSame(TransferStatus::Received, $t2->fresh()->status);
        $this->assertSame(TripStatus::Done, $trip->fresh()->status);
    }

    public function test_untouched_transfer_returns_to_pool_and_cancel_resets(): void
    {
        $store = $this->store();
        $wh = $this->warehouse();
        $a = $this->firstSet($this->accept($store));
        $b = $this->second($store);
        $t1 = $this->transfers()->create($store, $wh, [$a->id]);
        $t2 = $this->transfers()->create($store, $wh, [$b->id]);

        $trip = $this->trips()->create([$t1->id]);
        try {
            $this->trips()->create([$t1->id]);
            $this->fail('перемещение уже в ведомости');
        } catch (DomainError) {
        }
        try {
            $this->trips()->scanOut($trip, $b->qr_code);
            $this->fail('комплект не из ведомости');
        } catch (DomainError $e) {
            $this->assertStringContainsString($t2->number, $e->getMessage());
        }
        $this->trips()->addTransfers($trip, [$t2->id]);
        $this->trips()->scanOut($trip, $b->qr_code);
        $result = $this->trips()->ship($trip);
        $this->assertSame([$t2->id], $result['shipped']->pluck('id')->all());
        $this->assertNull($t1->fresh()->trip_id, 'без единого скана — обратно в пул');
        $this->assertSame(TransferStatus::Created, $t1->fresh()->status);

        $trip2 = $this->trips()->create([$t1->id]);
        $this->trips()->scanOut($trip2, $a->qr_code);
        $this->trips()->cancel($trip2);
        $this->assertSame(TripStatus::Cancelled, $trip2->fresh()->status);
        $this->assertNull($t1->fresh()->trip_id);
        $this->assertFalse((bool) $t1->lines()->first()->scanned_out);
    }

    public function test_remove_last_line_cancels_transfer(): void
    {
        $store = $this->store();
        $wh = $this->warehouse();
        $a = $this->firstSet($this->accept($store));
        $t = $this->transfers()->create($store, $wh, [$a->id]);
        $this->transfers()->removeLine($t, $t->lines()->first());
        $this->assertSame(TransferStatus::Cancelled, $t->fresh()->status);
        $this->assertCount(1, $this->transfers()->bufferCandidates($store), 'комплект снова доступен');
    }

    public function test_web_flow(): void
    {
        $store = $this->store();
        $wh = $this->warehouse();
        $a = $this->firstSet($this->accept($store));
        $b = $this->second($store);
        $keeper = $this->user(Role::Storekeeper, $store);
        $this->actingAs($keeper);

        $this->post('/transfers', ['from_location_id' => $store->id, 'to_location_id' => $wh->id, 'set_ids' => [$a->id]])->assertRedirect();
        $t = Transfer::firstOrFail();
        $this->post("/transfers/{$t->id}/sets", ['set_ids' => [$b->id]])->assertRedirect();
        $this->get('/transfers/create?transfer='.$t->id)->assertOk();
        $this->get('/tasks/transport')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Tasks/Transport')->has('pool', 1)->where('pool.0.contracts_count', 2));

        $this->post('/trips', ['transfer_ids' => [$t->id], 'driver' => 'Петров', 'vehicle' => 'А001АА'])->assertRedirect();
        $trip = $t->fresh()->trip;
        $this->get("/trips/{$trip->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Trips/Show')->where('trip.number', $trip->number));
        $this->postJson("/w/transfers/{$t->id}/scan-out", ['code' => $a->qr_code])->assertStatus(409);
        $this->postJson("/w/trips/{$trip->id}/scan-out", ['code' => $a->qr_code])->assertOk();
        $this->postJson("/w/trips/{$trip->id}/scan-out", ['code' => $b->qr_code])->assertOk();
        $this->get("/trips/{$trip->id}/print")->assertOk()->assertSee($trip->number)->assertSee('Петров')->assertSee($a->qr_code);
        $this->get("/transfers/{$t->id}/print")->assertOk()->assertSee($t->number);
        $this->post("/trips/{$trip->id}/ship")->assertRedirect();
        $this->assertSame(TransferStatus::Shipped, $t->fresh()->status);

        $this->postJson("/w/trips/{$trip->id}/scan-in", ['code' => $a->qr_code])->assertOk();
        $this->post("/trips/{$trip->id}/complete")->assertRedirect();
        $this->assertSame(TransferStatus::Discrepancy, $t->fresh()->status);
        $this->assertSame(TripStatus::Done, $trip->fresh()->status);
    }
}
