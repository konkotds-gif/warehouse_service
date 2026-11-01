<?php

namespace Tests\Feature\Domain;

use App\Domain\Storage\AcceptanceService;
use App\Domain\Storage\Condition;
use App\Domain\Storage\GarageSetState;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\DomainError;
use App\Models\Client;
use App\Models\Movement;
use App\Models\OutboxEvent;
use App\Models\StorageContract;
use Tests\TestCase;

class AcceptanceTest extends TestCase
{
    public function test_accept_creates_contract_set_garage_movement_and_outbox(): void
    {
        $store = $this->store();
        $tariff = $this->tariff();
        $contract = $this->accept($store, ['tariff_id' => $tariff->id]);

        $this->assertSame('000001', $contract->number);
        $this->assertEquals(4800, (float) $contract->amount);
        $set = $this->firstSet($contract);
        $this->assertSame('000001', $set->qr_code, 'QR = номер договора');
        $this->assertSame(SetStatus::Accepted, $set->status);
        $this->assertSame('М01', $set->currentAddress(), 'адрес — буфер магазина');
        $this->assertSame('205/55 R16 94H', $set->tire_size);

        $client = Client::first();
        $this->assertSame('+79123456789', $client->phone);
        $this->assertSame('А123ВС86', $contract->vehicle->plate);

        $garage = $set->garageSet;
        $this->assertSame(GarageSetState::InStorage, $garage->state);
        $this->assertSame($contract->id, $garage->last_contract_id);

        $this->assertSame(1, Movement::where('wheel_set_id', $set->id)->where('action', 'accept')->count());
        $this->assertEqualsCanonicalizing(['client.created', 'contract.created'], OutboxEvent::pluck('event_type')->all());
        $event = OutboxEvent::where('event_type', 'contract.created')->first();
        $this->assertSame('000001', $event->payload['contract_number']);
        $this->assertSame('М01', $event->payload['sets'][0]['address']);
    }

    public function test_numbers_are_sequential_and_second_set_gets_suffix(): void
    {
        $store = $this->store();
        $this->accept($store);
        $contract = $this->accept($store, [
            'client' => ['phone' => '+79123456789'],
            'sets' => [
                ['qty' => 4, 'tire_size' => '205/55 R16', 'with_rims' => true, 'condition' => 'used'],
                ['qty' => 4, 'tire_size' => '215/60 R17', 'with_rims' => false, 'condition' => 'new'],
            ],
        ]);
        $this->assertSame('000002', $contract->number);
        $this->assertSame(['000002', '000002/2'], $contract->sets()->pluck('qr_code')->all());
        $this->assertSame(1, Client::count(), 'клиент с тем же телефоном не дублируется');
    }

    public function test_garage_prefill_suggests_used_after_new_set_was_on_vehicle(): void
    {
        $store = $this->store();
        $contract = $this->accept($store, ['sets' => [['qty' => 4, 'tire_size' => '205/55 R16', 'with_rims' => true, 'condition' => 'new']]]);
        $garage = $this->firstSet($contract)->garageSet;
        $garage->update(['state' => GarageSetState::OnVehicle, 'last_issued_at' => now()]);

        $prefill = $garage->fresh()->prefill();
        $this->assertSame('used', $prefill['condition']);
        $this->assertTrue($prefill['condition_needs_confirm']);
        $this->assertSame(4, $prefill['qty']);
    }

    public function test_repeat_accept_from_garage_updates_garage_and_keeps_snapshot(): void
    {
        $store = $this->store();
        $first = $this->accept($store);
        $set = $this->firstSet($first);
        $garage = $set->garageSet;
        // выдали — комплект на машине
        $set->update(['status' => SetStatus::Issued, 'current_location_id' => null]);
        $garage->update(['state' => GarageSetState::OnVehicle]);

        $second = app(AcceptanceService::class)->accept([
            'store_id' => $store->id, 'client_id' => $first->client_id, 'vehicle_id' => $first->vehicle_id,
            'sets' => [array_merge($garage->fresh()->prefill(), ['qty' => 3])],
        ]);

        $newSet = $this->firstSet($second);
        $this->assertSame($garage->id, $newSet->garage_set_id);
        $this->assertSame(3, $newSet->qty);
        $this->assertSame(3, $garage->fresh()->qty, 'правка при приёме обновляет гараж');
        $this->assertSame(4, $set->fresh()->qty, 'старый договор хранит снимок «как приняли»');
        $this->assertSame(GarageSetState::InStorage, $garage->fresh()->state);
    }

    public function test_cannot_accept_garage_set_already_in_storage(): void
    {
        $store = $this->store();
        $first = $this->accept($store);
        $garage = $this->firstSet($first)->garageSet;
        $this->expectException(DomainError::class);
        app(AcceptanceService::class)->accept([
            'store_id' => $store->id, 'client_id' => $first->client_id, 'vehicle_id' => $first->vehicle_id,
            'sets' => [$garage->prefill()],
        ]);
    }

    public function test_validation_of_required_fields(): void
    {
        $store = $this->store();
        foreach ([
            ['sets' => [['qty' => 9, 'tire_size' => '205/55 R16', 'with_rims' => true, 'condition' => 'used']]],
            ['sets' => [['qty' => 4, 'tire_size' => '205', 'with_rims' => true, 'condition' => 'used']]],
            ['sets' => [['qty' => 4, 'tire_size' => '205/55 R16', 'with_rims' => true, 'condition' => 'bad']]],
            ['vehicle' => ['plate' => '']],
        ] as $override) {
            try {
                $this->accept($store, $override);
                $this->fail('ожидалась ошибка для '.json_encode($override, JSON_UNESCAPED_UNICODE));
            } catch (DomainError $e) {
                $this->assertSame(422, $e->status);
            }
        }
        $this->assertSame(0, StorageContract::count(), 'при ошибке ничего не сохраняется');
    }

    public function test_cannot_accept_at_warehouse(): void
    {
        $this->expectException(DomainError::class);
        $this->accept($this->warehouse());
    }

    public function test_condition_enum_saved(): void
    {
        $contract = $this->accept($this->store());
        $this->assertSame(Condition::Used, $this->firstSet($contract)->condition);
    }
}
