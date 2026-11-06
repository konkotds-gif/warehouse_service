<?php

namespace Tests\Feature\Api;

use App\Domain\Family\VehiclePersons;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\DomainError;
use App\Domain\Transfer\TransferService;
use App\Domain\Users\Role;
use App\Domain\Warehouse\PlacementService;
use App\Models\ApiKey;
use App\Models\BookingOrder;
use App\Models\Client;
use App\Models\GarageSet;
use App\Models\Vehicle;
use App\Models\VehiclePerson;
use App\Models\WheelSet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** API для онлайн-записи: поиск хранений по подтверждённому телефону, заказ под запись, гараж, доверенные лица. */
class BookingApiTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->key] = ApiKey::generate('Онлайн-запись', null, 'booking');
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'];
    }

    /** Комплект Иванова размещён на складе 02. */
    private function placedSet(): WheelSet
    {
        $store = $this->store();
        $wh = $this->warehouse('02');
        $set = $this->firstSet($this->accept($store));
        $t = app(TransferService::class);
        $tr = $t->create($store, $wh, [$set->id]);
        $t->scanOut($tr, $set->qr_code, $set->qty);
        $t->ship($tr);
        $t->scanIn($tr, $set->qr_code);
        app(PlacementService::class)->place($set->fresh(), '02_01_01');

        return $set->fresh();
    }

    public function test_keys_are_separated_by_scope(): void
    {
        [, $onec] = ApiKey::generate('1С УТ');
        $this->getJson('/api/v1/booking/lookup?phone=89123456789', ['X-Api-Key' => $onec])->assertStatus(403);
        $this->getJson('/api/v1/stock', $this->h())->assertStatus(403);
        $this->getJson('/api/v1/stock', ['X-Api-Key' => $onec])->assertOk();
        // Ключ, созданный до появления областей, остаётся ключом 1С
        $legacy = ApiKey::where('name', '1С УТ')->first();
        $legacy->update(['scopes' => null]);
        $this->getJson('/api/v1/stock', ['X-Api-Key' => $onec])->assertOk();
    }

    public function test_lookup_by_exact_phone_hides_cell_and_logs(): void
    {
        $set = $this->placedSet();
        $res = $this->getJson('/api/v1/booking/lookup?phone=%2B7 912 345-67-89&store_code=М01', $this->h())->assertOk();
        $res->assertJsonPath('client.full_name', 'Иванов Иван')
            ->assertJsonPath('vehicles.0.plate', 'А123ВС86')
            ->assertJsonPath('vehicles.0.role', 'owner')
            ->assertJsonPath('vehicles.0.stored_sets.0.set_id', $set->id)
            ->assertJsonPath('vehicles.0.stored_sets.0.state', 'in_storage')
            ->assertJsonPath('vehicles.0.stored_sets.0.orderable', true)
            ->assertJsonPath('vehicles.0.garage_sets.0.in_storage', true);
        $this->assertStringNotContainsString('02_01_01', $res->getContent());
        // Подвоз — на следующий день к 12:00
        $ready = Carbon::parse($res->json('vehicles.0.stored_sets.0.ready_from'))->timezone(config('app.display_timezone'));
        $this->assertSame(now(config('app.display_timezone'))->addDay()->toDateString().' 12:00', $ready->format('Y-m-d H:i'));

        // Хвост телефона не находит клиента (в отличие от поиска для 1С)
        $this->getJson('/api/v1/booking/lookup?phone=4567890', $this->h())->assertStatus(422);
        $this->getJson('/api/v1/booking/lookup?phone=89120000000', $this->h())->assertOk()->assertJsonCount(0, 'vehicles');
        $this->assertSame(2, DB::table('api_access_log')->where('action', 'lookup')->count());
    }

    public function test_foreign_plate_reveals_only_that_storage_exists(): void
    {
        $this->placedSet();
        $this->getJson('/api/v1/booking/lookup?phone=89220000000&plate=a123bc86', $this->h())->assertOk()
            ->assertJsonCount(0, 'vehicles')
            ->assertJsonPath('foreign_plate.known', true)
            ->assertJsonPath('foreign_plate.has_storage', true)
            ->assertJsonMissing(['full_name' => 'Иванов Иван']);
    }

    public function test_trusted_person_sees_vehicle_and_can_order(): void
    {
        $set = $this->placedSet();
        $vehicle = Vehicle::first();
        // Владелец добавляет жену в онлайн-записи
        $this->postJson("/api/v1/booking/vehicles/{$vehicle->id}/persons",
            ['phone' => '89123456789', 'person_phone' => '89220001122', 'person_name' => 'Иванова Мария'], $this->h())
            ->assertCreated()->assertJsonPath('full_name', 'Иванова Мария');
        // Посторонний добавить не может
        $this->postJson("/api/v1/booking/vehicles/{$vehicle->id}/persons",
            ['phone' => '89220001122', 'person_phone' => '89220003344', 'person_name' => 'Кто-то'], $this->h())->assertStatus(403);

        $res = $this->getJson('/api/v1/booking/lookup?phone=89220001122', $this->h())->assertOk();
        $res->assertJsonPath('vehicles.0.role', 'trusted')
            ->assertJsonPath('vehicles.0.owner_name', 'Иван И.')
            ->assertJsonPath('vehicles.0.persons', [])
            ->assertJsonPath('vehicles.0.my_person_id', VehiclePerson::first()->id)
            ->assertJsonPath('vehicles.0.stored_sets.0.set_id', $set->id);
        $this->assertStringNotContainsString('+79123456789', $res->getContent());

        $this->postJson('/api/v1/booking/orders', ['booking_code' => 'B-100', 'set_id' => $set->id, 'store_code' => 'М01',
            'starts_at' => now()->addDays(2)->setTime(10, 0)->toIso8601String(), 'phone' => '89220001122'], $this->h())
            ->assertCreated()->assertJsonPath('status', 'active');
        $this->assertSame(SetStatus::Ordered, $set->fresh()->status);

        // Владелец видит доверенное лицо и отзывает его
        $owner = $this->getJson('/api/v1/booking/lookup?phone=89123456789', $this->h())->json('vehicles.0.persons');
        $this->assertSame('Иванова Мария', $owner[0]['full_name']);
        $this->deleteJson("/api/v1/booking/vehicles/{$vehicle->id}/persons/{$owner[0]['id']}?phone=89123456789", [], $this->h())->assertOk();
        $this->getJson('/api/v1/booking/lookup?phone=89220001122', $this->h())->assertJsonCount(0, 'vehicles');
    }

    public function test_order_reschedule_cancel_and_idempotency(): void
    {
        $set = $this->placedSet();
        $body = ['booking_code' => 'B-1', 'set_id' => $set->id, 'store_code' => 'М01',
            'starts_at' => now()->addDays(3)->setTime(11, 0)->toIso8601String(), 'phone' => '89123456789'];
        $h = $this->h() + ['Idempotency-Key' => 'booking:B-1:1'];
        $this->postJson('/api/v1/booking/orders', $body, $h)->assertCreated();
        $this->postJson('/api/v1/booking/orders', $body, $h)->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(1, BookingOrder::count());
        $this->assertSame(SetStatus::Ordered, $set->fresh()->status);
        $this->assertSame(now()->addDays(3)->toDateString(), $set->fresh()->issue_due_date->toDateString());

        // Повторный заказ того же комплекта под другую запись — нельзя
        $this->postJson('/api/v1/booking/orders', ['booking_code' => 'B-2'] + $body, $this->h())->assertStatus(409);
        // Чужой телефон — нельзя
        $this->postJson('/api/v1/booking/orders', ['booking_code' => 'B-3', 'phone' => '89220000000'] + $body, $this->h())->assertStatus(403);

        $new = now()->addDays(5)->setTime(9, 0)->toIso8601String();
        $this->patchJson('/api/v1/booking/orders/B-1', ['starts_at' => $new], $this->h())->assertOk();
        $this->assertSame(now()->addDays(5)->toDateString(), $set->fresh()->issue_due_date->toDateString());
        $this->getJson('/api/v1/booking/lookup?phone=89123456789', $this->h())
            ->assertJsonPath('vehicles.0.stored_sets.0.booking_code', 'B-1')
            ->assertJsonPath('vehicles.0.stored_sets.0.orderable', false);

        $this->deleteJson('/api/v1/booking/orders/B-1', [], $this->h())->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(SetStatus::Placed, $set->fresh()->status);
        $this->getJson('/api/v1/booking/orders/B-1', $this->h())->assertOk()->assertJsonPath('status', 'cancelled');
        $this->deleteJson('/api/v1/booking/orders/NOPE', [], $this->h())->assertOk()->assertJsonPath('status', 'none');
    }

    public function test_garage_import_only_adds_missing_data(): void
    {
        $this->accept($this->store()); // Иванов: Kia Rio А123ВС86, летний 205/55 R16
        $res = $this->postJson('/api/v1/booking/garage', ['phone' => '89123456789', 'full_name' => 'Иван', 'vehicles' => [
            ['ref' => 1, 'plate' => 'А123ВС86', 'make' => 'Toyota', 'model' => 'Camry', 'wheels' => [
                ['ref' => 11, 'season' => 'summer', 'tire_size' => '215/60 R17'],
                ['ref' => 12, 'season' => 'winter', 'studded' => true, 'tire_size' => '205/55 R16', 'brand' => 'Nokian', 'model' => 'Hakka 10', 'rim_type' => 'steel'],
            ]],
            ['ref' => 2, 'plate' => 'В777ВВ86', 'make' => 'Lada', 'model' => 'Vesta', 'wheels' => []],
            ['ref' => 3, 'plate' => null, 'make' => 'BMW', 'model' => 'X5'],
        ]], $this->h())->assertOk();
        $res->assertJsonPath('created_client', false)
            ->assertJsonPath('vehicles.0.status', 'matched')
            ->assertJsonPath('vehicles.0.garage_sets.0.status', 'exists')
            ->assertJsonPath('vehicles.0.garage_sets.1.status', 'created')
            ->assertJsonPath('vehicles.1.status', 'created')
            ->assertJsonPath('vehicles.2.status', 'skipped');
        $kia = Vehicle::where('plate', 'А123ВС86')->first();
        $this->assertSame('Kia', $kia->make); // хранение главнее — марку не перезаписали
        $winter = GarageSet::where('vehicle_id', $kia->id)->where('season', 'winter_studded')->first();
        $this->assertSame('Nokian Hakka 10', $winter->brand_model);
        $this->assertTrue($winter->with_rims);
        $this->assertSame(2, Client::first()->vehicles()->count());

        // Новый клиент из записи; чужая машина не трогается
        $this->postJson('/api/v1/booking/garage', ['phone' => '89990001122', 'full_name' => 'Петров Пётр', 'vehicles' => [
            ['ref' => 1, 'plate' => 'а123вс86', 'make' => 'Kia', 'model' => 'Rio'],
        ]], $this->h())->assertOk()->assertJsonPath('created_client', true)->assertJsonPath('vehicles.0.status', 'foreign');
        $this->assertSame('Петров Пётр', Client::where('phone', '+79990001122')->first()->full_name);
    }

    public function test_owner_change_revokes_trusted_and_merge_moves_them(): void
    {
        $this->accept($this->store());
        $vehicle = Vehicle::first();
        $persons = app(VehiclePersons::class);
        $wife = $persons->add($vehicle, null, ['phone' => '89220001122', 'full_name' => 'Иванова Мария']);
        $this->assertTrue($wife->client->canUseVehicle($vehicle));

        // Дубль жены объединяют с основной карточкой — доверенность переходит
        $main = Client::create(['full_name' => 'Иванова М.', 'phone' => '89220009999']);
        $this->actingAs($this->user());
        $this->post("/clients/{$main->id}/merge", ['other_id' => $wife->client_id])->assertRedirect();
        $this->assertTrue($main->fresh()->canUseVehicle($vehicle));

        $buyer = Client::create(['full_name' => 'Покупатель', 'phone' => '89000000001']);
        // Колёса на хранении — смену владельца проверяем на машине без хранения
        $v2 = Client::first()->vehicles()->create(['plate' => 'Е001ЕЕ86', 'make' => 'Kia', 'model' => 'Ceed']);
        $persons->add($v2, $main);
        $this->post("/vehicles/{$v2->id}/owner", ['client_id' => $buyer->id, 'sets_moved' => true])->assertRedirect();
        $this->assertSame(0, VehiclePerson::active()->where('vehicle_id', $v2->id)->count());
        $this->assertSame('owner_changed', VehiclePerson::where('vehicle_id', $v2->id)->first()->revoked_reason);
    }

    public function test_trusted_person_drops_off_wheels_on_own_contract(): void
    {
        $store = $this->store();
        $first = $this->accept($store);
        $vehicle = $first->vehicle;
        $wife = app(VehiclePersons::class)->add($vehicle, null, ['phone' => '89220001122', 'full_name' => 'Иванова Мария']);

        $contract = $this->accept($store, ['client' => null, 'client_id' => $wife->client_id, 'vehicle' => null, 'vehicle_id' => $vehicle->id,
            'sets' => [['qty' => 4, 'tire_size' => '205/55 R16', 'with_rims' => false, 'condition' => 'used', 'season' => 'winter']]]);
        $this->assertSame($wife->client_id, $contract->client_id);
        $this->assertSame($vehicle->id, $contract->vehicle_id);

        // В мастере приёма у жены машина мужа видна как доверенная
        $this->actingAs($this->user());
        $this->get('/accept?client='.$wife->client_id)->assertInertia(fn ($p) => $p->where('garage.0.id', $vehicle->id)
            ->where('garage.0.access', 'trusted')->where('garage.0.owner.full_name', 'Иванов Иван'));
        // Карточка авто показывает доверенных лиц
        $this->get('/vehicles/'.$vehicle->id)->assertInertia(fn ($p) => $p->where('persons.0.client.full_name', 'Иванова Мария'));

        // Посторонний — нельзя
        $stranger = Client::create(['full_name' => 'Чужой', 'phone' => '89000000002']);
        $this->expectException(DomainError::class);
        $this->accept($store, ['client' => null, 'client_id' => $stranger->id, 'vehicle' => null, 'vehicle_id' => $vehicle->id]);
    }

    public function test_staff_adds_and_revokes_person_in_vehicle_card(): void
    {
        $this->accept($this->store());
        $vehicle = Vehicle::first();
        $this->actingAs($this->user(Role::Seller));
        $this->post("/vehicles/{$vehicle->id}/persons", ['phone' => '89220001122'])->assertSessionHasErrors('full_name');
        $this->post("/vehicles/{$vehicle->id}/persons", ['phone' => '89220001122', 'full_name' => 'Иванова Мария', 'note' => 'супруга'])
            ->assertSessionHasNoErrors();
        $this->post("/vehicles/{$vehicle->id}/persons", ['phone' => '89123456789'])->assertSessionHasErrors('phone'); // это владелец
        $p = VehiclePerson::active()->first();
        $this->assertSame('staff', $p->source);
        $this->delete("/vehicles/{$vehicle->id}/persons/{$p->id}")->assertRedirect();
        $this->assertSame('staff', $p->fresh()->revoked_reason);
    }
}
