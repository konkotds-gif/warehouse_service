<?php

namespace Tests\Feature\Web;

use App\Domain\Cars\CarCatalogSync;
use App\Domain\Users\Role;
use App\Models\CarMake;
use App\Models\CarModel;
use App\Models\Setting;
use App\Models\Vehicle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CarCatalogTest extends TestCase
{
    private const URL = 'https://zapis.diskimira.com/api/v1/vehicles';

    private function catalog(array $makes, string $version = 'v1'): array
    {
        return ['data' => $makes, 'meta' => ['makes_count' => count($makes), 'models_count' => 0, 'version' => $version, 'generated_at' => now()->toIso8601String()]];
    }

    private function lada(bool $vestaActive = true): array
    {
        return ['id' => 12, 'slug' => 'lada', 'name' => 'Лада (ВАЗ)', 'aliases' => ['ваз', 'жигули'], 'is_popular' => true, 'is_active' => true, 'models' => [
            ['id' => 501, 'slug' => 'vesta', 'name' => 'Vesta', 'is_active' => $vestaActive],
            ['id' => 502, 'slug' => 'granta', 'name' => 'Granta', 'is_active' => true],
        ]];
    }

    private function kia(): array
    {
        return ['id' => 20, 'slug' => 'kia', 'name' => 'Kia', 'aliases' => ['киа'], 'is_popular' => true, 'is_active' => true, 'models' => [
            ['id' => 601, 'slug' => 'rio', 'name' => 'Rio', 'is_active' => true],
        ]];
    }

    private function configure(): void
    {
        Setting::put(CarCatalogSync::URL, self::URL);
        Setting::putSecret(CarCatalogSync::KEY, 'tb_secret_key_123');
    }

    public function test_key_is_stored_encrypted(): void
    {
        $this->configure();
        $raw = DB::table('settings')->where('key', CarCatalogSync::KEY)->value('value');
        $this->assertStringNotContainsString('tb_secret_key_123', $raw);
        $this->assertSame('tb_secret_key_123', Setting::getSecret(CarCatalogSync::KEY));
    }

    public function test_sync_upserts_uses_etag_and_deactivates_removed(): void
    {
        $this->configure();
        Http::fakeSequence(self::URL.'/catalog*')
            ->push($this->catalog([$this->lada(), $this->kia()], 'v1'), 200, ['ETag' => '"v1"'])
            ->push('', 304)
            ->push($this->catalog([$this->lada(false)], 'v2'), 200);

        $this->artisan('cars:sync')->expectsOutputToContain('Загружено марок: 2, моделей: 3')->assertSuccessful();
        $this->assertSame(['ваз', 'жигули'], CarMake::find(12)->aliases);
        $this->assertSame('v1', Setting::get(CarCatalogSync::VERSION));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer tb_secret_key_123') && $r['include_inactive'] == 1);

        $this->artisan('cars:sync')->expectsOutputToContain('не менялся')->assertSuccessful();
        Http::assertSent(fn (Request $r) => $r->hasHeader('If-None-Match', '"v1"'));

        $this->artisan('cars:sync')->assertSuccessful();
        $this->assertFalse(CarMake::find(20)->is_active);   // Kia пропала из ответа
        $this->assertFalse(CarModel::find(601)->is_active);
        $this->assertFalse(CarModel::find(501)->is_active); // Vesta скрыта в справочнике
        $this->assertTrue(CarModel::find(502)->is_active);
        $this->assertSame('v2', Setting::get(CarCatalogSync::VERSION));
        $this->assertSame('updated', Setting::get(CarCatalogSync::LAST)['status']);
    }

    public function test_errors_are_recorded_and_empty_catalog_is_not_applied(): void
    {
        $this->configure();
        Http::fakeSequence(self::URL.'/catalog*')
            ->push($this->catalog([$this->kia()]), 200)
            ->push(['error' => 'unauthorized', 'message' => 'Ключ отозван'], 401)
            ->push($this->catalog([]), 200);

        $this->artisan('cars:sync')->assertSuccessful();
        $this->artisan('cars:sync')->expectsOutputToContain('401')->assertFailed();
        $this->assertSame('error', Setting::get(CarCatalogSync::LAST)['status']);
        $this->artisan('cars:sync --force')->expectsOutputToContain('пустой')->assertFailed();
        $this->assertTrue(CarMake::find(20)->is_active);
        $this->assertNotNull(Setting::get(CarCatalogSync::LAST_SUCCESS));
    }

    public function test_sync_is_skipped_without_key(): void
    {
        Http::fake();
        $this->artisan('cars:sync')->expectsOutputToContain('пропущена')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_settings_page_save_test_and_sync(): void
    {
        $admin = $this->user(Role::Admin);
        $this->actingAs($admin)->get('/admin/settings')
            ->assertInertia(fn (Assert $p) => $p->component('Admin/Settings')->where('cars.key_set', false)->where('cars.api_url', CarCatalogSync::DEFAULT_URL));

        $this->put('/admin/settings/cars', ['api_url' => 'http://insecure.example'])->assertSessionHasErrors('api_url');
        $this->put('/admin/settings/cars', ['api_url' => self::URL.'/', 'api_key' => 'tb_abcdef_secret_9876'])->assertSessionHas('success');
        $this->assertSame(self::URL, Setting::get(CarCatalogSync::URL));

        $this->get('/admin/settings')->assertInertia(fn (Assert $p) => $p->where('cars.key_set', true)->where('cars.key_mask', 'tb_abc…9876'))
            ->assertDontSee('tb_abcdef_secret_9876');

        Http::fake([
            self::URL.'/makes' => Http::response(['data' => [['id' => 20, 'slug' => 'kia', 'name' => 'Kia']]]),
            self::URL.'/catalog*' => Http::response($this->catalog([$this->kia()])),
        ]);
        $this->post('/admin/settings/cars/test')->assertSessionHas('success', fn ($m) => str_contains($m, 'Марок в справочнике: 1'));
        $this->post('/admin/settings/cars/sync')->assertSessionHas('success');
        $this->assertSame(1, CarMake::count());

        // Ключ без ввода не затирается; удаление — явное
        $this->put('/admin/settings/cars', ['api_url' => self::URL, 'api_key' => ''])->assertSessionHas('success');
        $this->assertSame('tb_abcdef_secret_9876', Setting::getSecret(CarCatalogSync::KEY));
        $this->put('/admin/settings/cars', ['api_url' => self::URL, 'remove_key' => true]);
        $this->assertNull(Setting::getSecret(CarCatalogSync::KEY));

        $this->actingAs($this->user(Role::Seller))->get('/admin/settings')->assertForbidden();
    }

    public function test_select_endpoints_and_vehicle_binding(): void
    {
        $this->configure();
        Http::fake([self::URL.'/catalog*' => Http::response($this->catalog([$this->lada(), $this->kia()]))]);
        $this->artisan('cars:sync')->assertSuccessful();

        $store = $this->store();
        $this->actingAs($this->user(Role::Seller, $store));
        $this->getJson('/w/car-makes')->assertOk()->assertJsonFragment(['value' => 'Лада (ВАЗ)', 'aliases' => ['ваз', 'жигули'], 'popular' => true]);
        $this->getJson('/w/car-models?make='.urlencode('Лада (ВАЗ)'))->assertExactJson(['Granta', 'Vesta']);
        $this->getJson('/w/car-models?make=ваз')->assertExactJson(['Granta', 'Vesta']); // по алиасу
        $this->getJson('/w/car-models?make=Нет такой')->assertExactJson([]);

        // Приём: марка/модель из справочника привязываются по id и приводятся к написанию справочника
        $contract = $this->accept($store, ['vehicle' => ['make' => 'kia', 'model' => 'rio']]);
        $v = Vehicle::find($contract->vehicle_id);
        $this->assertSame(['Kia', 'Rio', 20, 601], [$v->make, $v->model, $v->car_make_id, $v->car_model_id]);

        // Нет в справочнике — сохраняется как ввели, без привязки
        $other = Vehicle::create(['client_id' => $v->client_id, 'plate' => 'В777ВВ86', 'make' => 'Москвич', 'model' => '412']);
        $this->assertSame(['Москвич', null], [$other->make, $other->car_make_id]);
    }
}
