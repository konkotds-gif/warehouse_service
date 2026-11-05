<?php

namespace Tests\Feature\Domain;

use App\Domain\Storage\Damage;
use App\Models\Client;
use Tests\TestCase;

class AcceptanceExtrasTest extends TestCase
{
    public function test_phone_is_normalized_from_any_input(): void
    {
        $contract = $this->accept($this->store(), ['client' => ['phone' => '+7 (912) 345-67-89']]);
        $this->assertSame('+79123456789', $contract->client->phone);

        // тот же телефон, набранный с восьмёрки и пробелами, находит того же клиента
        $again = $this->accept($this->store('М05'), ['client' => ['phone' => '8 912 345 67 89'], 'vehicle' => ['plate' => 'В777ОР86']]);
        $this->assertSame($contract->client_id, $again->client_id);
        $this->assertSame(1, Client::count());
    }

    public function test_new_vehicle_without_plate(): void
    {
        $contract = $this->accept($this->store(), ['vehicle' => ['plate' => null, 'no_plate' => true]]);
        $this->assertNull($contract->vehicle->plate);
        $this->assertSame('Kia', $contract->vehicle->make);
    }

    public function test_plate_required_unless_no_plate(): void
    {
        $this->expectExceptionMessage('госномер');
        $this->accept($this->store(), ['vehicle' => ['plate' => '']]);
    }

    public function test_damages_saved_for_used_and_cleared_for_new(): void
    {
        $set = $this->firstSet($this->accept($this->store(), ['sets' => [[
            'condition' => 'used', 'with_rims' => false,
            'damages' => ['scuffs', 'scratches', 'rim_curb', 'unknown', 'scuffs'],
        ]]]));
        // порядок справочника, без дублей и мусора; диски не учитываются без дисков
        $this->assertSame(['scratches', 'scuffs'], $set->damages);
        $this->assertSame(['scratches', 'scuffs'], $set->garageSet->damages);
        $this->assertSame(['Царапины', 'Потёртости'], Damage::labels($set->damages));

        $new = $this->firstSet($this->accept($this->store('М05'), ['vehicle' => ['plate' => 'Е001КХ86'], 'sets' => [[
            'condition' => 'new', 'damages' => ['scratches'],
        ]]]));
        $this->assertSame([], $new->damages);
    }
}
