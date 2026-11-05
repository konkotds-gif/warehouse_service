<?php

namespace Tests\Feature\Web;

use App\Domain\Storage\GarageSetState;
use App\Domain\Users\Role;
use App\Models\Client;
use App\Models\GarageSet;
use App\Models\Vehicle;
use App\Models\VehicleOwnerChange;
use App\Models\WheelSet;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VehiclesTest extends TestCase
{
    public function test_index_lists_vehicles_with_owner_and_storage_and_filters(): void
    {
        $contract = $this->accept($this->store());
        $other = Client::create(['full_name' => 'Петров Пётр', 'phone' => '+79120000001']);
        $other->vehicles()->create(['plate' => 'В777ВВ 86', 'make' => 'Lada', 'model' => 'Vesta']);
        $this->actingAs($this->user(Role::Seller));

        $this->get('/vehicles')->assertInertia(fn (Assert $p) => $p->component('Vehicles/Index')
            ->has('vehicles.data', 2));

        $this->get('/vehicles?q=a123')->assertInertia(fn (Assert $p) => $p
            ->has('vehicles.data', 1)
            ->where('vehicles.data.0.id', $contract->vehicle_id)
            ->where('vehicles.data.0.client.full_name', 'Иванов Иван')
            ->where('vehicles.data.0.stored_sets', 1)
            ->where('vehicles.data.0.contracts_count', 1));

        $this->get('/vehicles?q=Петров')->assertInertia(fn (Assert $p) => $p->has('vehicles.data', 1)->where('vehicles.data.0.make', 'Lada'));
        $this->get('/vehicles?q=Kia Rio')->assertInertia(fn (Assert $p) => $p->has('vehicles.data', 1));
        $this->get('/vehicles?only=stored')->assertInertia(fn (Assert $p) => $p->has('vehicles.data', 1)->where('vehicles.data.0.id', $contract->vehicle_id));
    }

    public function test_show_card_has_owner_garage_and_contracts(): void
    {
        $contract = $this->accept($this->store());
        $this->actingAs($this->user(Role::Seller));

        $this->get('/vehicles/'.$contract->vehicle_id)->assertInertia(fn (Assert $p) => $p->component('Vehicles/Show')
            ->where('vehicle.plate', 'А123ВС86')
            ->where('client.id', $contract->client_id)
            ->has('garageSets', 1)
            ->has('contracts', 1)
            ->where('contracts.0.number', $contract->number)
            ->where('inStorage', true));
    }

    public function test_create_vehicle_with_owner_and_extra_fields(): void
    {
        $client = Client::create(['full_name' => 'Сидоров']);
        $this->actingAs($this->user(Role::Seller));

        $this->post('/vehicles', ['client_id' => $client->id, 'plate' => 'е001кх 186', 'make' => 'Toyota', 'model' => 'Camry',
            'vin' => 'jtnb11hk 0j3000001', 'year' => 2018, 'color' => 'белый'])->assertRedirect();

        $v = Vehicle::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('Е001КХ186', $v->plate);
        $this->assertSame('JTNB11HK0J3000001', $v->vin);
        $this->assertSame(2018, $v->year);
        $this->assertSame('белый', $v->color);
    }

    public function test_plate_must_be_unique_across_clients_in_vehicles_section(): void
    {
        $contract = $this->accept($this->store());
        $client = Client::create(['full_name' => 'Сидоров']);
        $this->actingAs($this->user(Role::Seller));

        $this->post('/vehicles', ['client_id' => $client->id, 'plate' => 'А123ВС 86', 'make' => 'Kia', 'model' => 'Rio'])
            ->assertSessionHasErrors('plate');
        $this->assertSame(0, $client->vehicles()->count());

        // Изменение других полей без смены номера не мешает
        $this->put('/vehicles/'.$contract->vehicle_id, ['plate' => 'А123ВС86', 'make' => 'Kia', 'model' => 'Rio', 'year' => 2015])
            ->assertSessionHasNoErrors();
        $this->assertSame(2015, $contract->vehicle->fresh()->year);
    }

    public function test_vin_validation(): void
    {
        $client = Client::create(['full_name' => 'Сидоров']);
        $this->actingAs($this->user(Role::Seller));
        $this->post('/vehicles', ['client_id' => $client->id, 'no_plate' => true, 'make' => 'Kia', 'model' => 'Rio', 'vin' => 'ВИН!'])
            ->assertSessionHasErrors('vin');
    }

    public function test_change_owner_moves_garage_sets_and_keeps_history(): void
    {
        $store = $this->store();
        $contract = $this->accept($store);
        $vehicle = $contract->vehicle;
        $set = $this->firstSet($contract);
        $buyer = Client::create(['full_name' => 'Покупатель']);
        $user = $this->user(Role::Seller);
        $this->actingAs($user);

        // Колёса на хранении — смена владельца запрещена
        $this->post("/vehicles/{$vehicle->id}/owner", ['client_id' => $buyer->id, 'sets_moved' => true])->assertSessionHasErrors('domain');
        $this->assertSame($contract->client_id, $vehicle->fresh()->client_id);

        WheelSet::whereKey($set->id)->update(['status' => 'issued']);

        $this->post("/vehicles/{$vehicle->id}/owner", ['client_id' => $buyer->id, 'sets_moved' => true, 'note' => 'продал'])
            ->assertSessionHasNoErrors();
        $this->assertSame($buyer->id, $vehicle->fresh()->client_id);
        $this->assertSame($contract->client_id, $contract->fresh()->client_id, 'договор остаётся за прежним владельцем');
        $this->assertNotSame(GarageSetState::Retired, GarageSet::where('vehicle_id', $vehicle->id)->first()->state);

        $log = VehicleOwnerChange::firstOrFail();
        $this->assertSame($contract->client_id, $log->from_client_id);
        $this->assertSame($buyer->id, $log->to_client_id);
        $this->assertSame($user->id, $log->user_id);

        $this->get('/vehicles/'.$vehicle->id)->assertInertia(fn (Assert $p) => $p->has('owners', 1)
            ->where('owners.0.to.full_name', 'Покупатель')->where('client.id', $buyer->id));
    }

    public function test_change_owner_without_sets_retires_them(): void
    {
        $client = Client::create(['full_name' => 'Продавец']);
        $vehicle = $client->vehicles()->create(['plate' => 'М001ММ86', 'make' => 'Kia', 'model' => 'Rio']);
        $gs = GarageSet::create(['vehicle_id' => $vehicle->id, 'title' => 'Зима', 'qty' => 4, 'tire_size' => '205/55R16',
            'with_rims' => false, 'condition' => 'used']);
        $buyer = Client::create(['full_name' => 'Покупатель']);
        $this->actingAs($this->user(Role::Seller));

        $this->post("/vehicles/{$vehicle->id}/owner", ['client_id' => $buyer->id, 'sets_moved' => false])->assertSessionHasNoErrors();
        $this->assertSame(GarageSetState::Retired, $gs->fresh()->state);
        $this->assertFalse(VehicleOwnerChange::first()->sets_moved);
    }

    public function test_delete_requires_fix_and_no_sets_in_storage(): void
    {
        $contract = $this->accept($this->store());
        $vehicle = $contract->vehicle;

        $this->actingAs($this->user(Role::Seller));
        $this->delete('/vehicles/'.$vehicle->id)->assertForbidden();

        $this->actingAs($this->user());
        $this->delete('/vehicles/'.$vehicle->id)->assertSessionHasErrors('domain');
        WheelSet::where('contract_id', $contract->id)->update(['status' => 'issued']);
        $this->delete('/vehicles/'.$vehicle->id)->assertRedirect('/clients/'.$contract->client_id);
        $this->assertSoftDeleted($vehicle);
        $this->get('/contracts/'.$contract->id)->assertOk();
    }

    public function test_client_card_vehicle_create_accepts_new_fields(): void
    {
        $client = Client::create(['full_name' => 'Сидоров']);
        $this->actingAs($this->user(Role::Seller));
        $this->post("/clients/{$client->id}/vehicles", ['plate' => 'Р222РР86', 'make' => 'Kia', 'model' => 'Rio', 'color' => 'синий'])
            ->assertSessionHasNoErrors();
        $this->assertSame('синий', $client->vehicles()->first()->color);
    }
}
