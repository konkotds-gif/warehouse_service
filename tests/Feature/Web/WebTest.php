<?php

namespace Tests\Feature\Web;

use App\Domain\Storage\SetStatus;
use App\Domain\Transfer\TransferService;
use App\Domain\Users\Role;
use App\Models\Client;
use App\Models\Location;
use App\Models\StorageContract;
use App\Models\TagPrint;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebTest extends TestCase
{
    public function test_login_logout_and_lockout(): void
    {
        $user = User::factory()->create(['login' => 'ivan', 'password' => 'secret123']);
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->component('Auth/Login'));

        $this->post('/login', ['login' => 'ivan', 'password' => 'wrong'])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => 'ivan', 'password' => 'secret123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'ivan', 'password' => 'wrong']);
        }
        $this->post('/login', ['login' => 'ivan', 'password' => 'secret123'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create(['login' => 'off', 'password' => 'secret123', 'is_active' => false]);
        $this->post('/login', ['login' => 'off', 'password' => 'secret123'])->assertSessionHasErrors('login');
    }

    public function test_all_pages_render_for_admin(): void
    {
        $store = $this->store();
        $wh = $this->warehouse();
        $contract = $this->accept($store);
        $transfer = app(TransferService::class)->create($store, $wh, [$this->firstSet($contract)->id]);
        $this->actingAs($this->user());

        $pages = [
            '/' => 'Dashboard', '/search?q=Иванов' => 'Search', '/clients' => 'Clients/Index',
            '/clients/'.$contract->client_id => 'Clients/Show', '/vehicles' => 'Vehicles/Index',
            '/vehicles/'.$contract->vehicle_id => 'Vehicles/Show', '/accept' => 'Accept/Wizard',
            '/accept?client='.$contract->client_id => 'Accept/Wizard', '/contracts' => 'Contracts/Index',
            '/contracts/'.$contract->id => 'Contracts/Show', '/scan/place' => 'Scan/Place', '/scan/issue' => 'Scan/Issue',
            '/transfers' => 'Transfers/Index', '/transfers/create?from='.$store->id => 'Transfers/Create',
            '/transfers/'.$transfer->id => 'Transfers/Show', '/tasks' => 'Tasks/Index', '/inventories' => 'Inventory/Index',
            '/locations' => 'Locations/Index', '/locations/'.$wh->id => 'Locations/Show', '/reports' => 'Reports/Index',
            '/admin/users' => 'Admin/Users', '/admin/tariffs' => 'Admin/Tariffs', '/admin/integration' => 'Admin/Integration', '/admin/settings' => 'Admin/Settings',
        ];
        foreach ($pages as $url => $component) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component($component));
        }
        $this->get('/search?q='.$contract->number)->assertRedirect('/contracts/'.$contract->id);
        $this->get('/locations/'.$wh->id.'/labels')->assertOk()->assertSee('02_01_01');
        $this->get('/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_role_restrictions(): void
    {
        $store = $this->store();
        $this->actingAs($this->user(Role::Driver));
        $this->get('/accept')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/transfers')->assertOk();

        $this->actingAs($this->user(Role::Seller, $store));
        $this->get('/accept')->assertOk();
        $this->get('/scan/place')->assertForbidden();
        $this->get('/reports')->assertForbidden();
        $this->post('/locations', ['code' => '05', 'name' => 'X', 'type' => 'warehouse'])->assertForbidden();
    }

    public function test_accept_via_form_redirects_to_contract_with_print(): void
    {
        $store = $this->store();
        $seller = $this->user(Role::Seller, $store);
        $this->actingAs($seller)->post('/accept', $this->acceptData($store))
            ->assertRedirect()->assertSessionHas('success');
        $contract = StorageContract::firstOrFail();
        $this->assertSame($seller->id, $contract->accepted_by);

        $other = $this->store('М05');
        $this->post('/accept', $this->acceptData($other))->assertForbidden();

        // Ошибка предметной области возвращается к форме
        $this->post('/accept', $this->acceptData($store, ['sets' => [['tire_size' => 'abc']]]))->assertSessionHasErrors();
    }

    public function test_print_tags_logs_and_zpl(): void
    {
        $store = $this->store();
        $contract = $this->accept($store);
        $this->actingAs($this->user(Role::Seller, $store));

        $html = $this->post("/contracts/{$contract->id}/tags", ['receipt' => 1])->assertOk();
        $this->assertSame(4, substr_count($html->getContent(), 'class="tag"'), '4 колеса — 4 бирки');
        $html->assertSee('Договор (квитанция) хранения № 000001', false);

        $set = $this->firstSet($contract);
        $zpl = $this->post("/contracts/{$contract->id}/tags.zpl", ['set_id' => $set->id, 'copies' => 1, 'reason' => 'reprint'])->assertOk();
        $this->assertStringContainsString('^FDQA,000001^FS', $zpl->getContent());
        $this->assertStringContainsString('^PQ1', $zpl->getContent());

        $this->assertSame(2, TagPrint::count());
        $this->assertTrue($set->movements()->where('action', 'reprint')->exists());
    }

    public function test_scan_place_and_issue_json_endpoints(): void
    {
        $store = $this->store();
        $wh = $this->warehouse();
        $set = $this->firstSet($this->accept($store));
        $svc = app(TransferService::class);
        $t = $svc->create($store, $wh, [$set->id]);

        $keeper = $this->user(Role::Storekeeper, $wh);
        $this->actingAs($keeper);
        $this->postJson("/w/transfers/{$t->id}/scan-out", ['code' => $set->qr_code])->assertOk();
        $this->post("/transfers/{$t->id}/ship")->assertRedirect();
        $this->postJson("/w/transfers/{$t->id}/scan-in", ['code' => $set->qr_code])->assertOk();

        $this->getJson('/w/scan?code='.$set->qr_code)->assertOk()
            ->assertJsonPath('sets.0.suggested', '02_01_01')->assertJsonPath('sets.0.status', 'received');
        $this->postJson('/w/place', ['code' => $set->qr_code, 'address' => '02_09_09'])->assertStatus(404);
        $this->postJson('/w/place', ['code' => $set->qr_code, 'address' => '02_01_01', 'suggested' => '02_01_01'])
            ->assertOk()->assertJsonPath('set.address', '02_01_01');
        $this->getJson('/w/scan?code=999999')->assertStatus(404);

        // Заказ и доставка в магазин, выдача продавцом
        $seller = $this->user(Role::Seller, $store);
        $this->actingAs($seller)->post("/sets/{$set->id}/order", ['store_id' => $store->id, 'date' => now()->addDay()->toDateString()])
            ->assertSessionHas('success');
        $t2 = $svc->create($wh, $store, [$set->id]);
        $svc->scanOut($t2, $set->qr_code);
        $svc->ship($t2);
        $svc->scanIn($t2, $set->qr_code);
        $this->assertSame(SetStatus::Ready, $set->fresh()->status);

        $this->postJson("/sets/{$set->id}/issue", ['code' => 'чужой', 'qty' => 4])->assertStatus(409);
        $this->postJson("/sets/{$set->id}/issue", ['code' => $set->qr_code, 'qty' => 4])->assertOk();
        $this->assertSame(SetStatus::Issued, $set->fresh()->status);
    }

    public function test_location_admin_and_cell_generation(): void
    {
        $this->actingAs($this->user());
        $this->post('/locations', ['code' => '7', 'name' => 'Склад', 'type' => 'warehouse'])->assertSessionHasErrors('code');
        $this->post('/locations', ['code' => '07', 'name' => 'Склад 7', 'type' => 'warehouse', 'is_active' => true])->assertRedirect();
        $loc = Location::where('code', '07')->first();
        $this->post("/locations/{$loc->id}/generate", ['row_from' => 1, 'row_to' => 2, 'cells' => 5])->assertSessionHas('success');
        $this->assertSame(10, $loc->cells()->count());
        $this->assertSame('07_02_05', $loc->cells()->orderByDesc('address')->value('address'));
    }

    public function test_garage_management_and_client_merge(): void
    {
        $store = $this->store();
        $a = $this->accept($store);
        $dup = Client::create(['full_name' => 'Иванов (дубль)', 'phone' => '+79000000000']);
        $dup->vehicles()->create(['plate' => 'Х111ХХ86', 'make' => 'Lada', 'model' => 'Granta']);
        $this->actingAs($this->user());

        $this->post("/clients/{$a->client_id}/merge", ['other_id' => $dup->id])->assertRedirect();
        $this->assertSame(2, $a->client->vehicles()->count());
        $this->assertSoftDeleted('clients', ['id' => $dup->id]);

        $garage = $this->firstSet($a)->garageSet;
        $newVehicle = $a->client->vehicles()->where('plate', 'Х111ХХ86')->first();
        $this->post("/garage-sets/{$garage->id}/move", ['vehicle_id' => $newVehicle->id])->assertSessionHas('success');
        $this->post("/garage-sets/{$garage->id}/retire")->assertSessionHasErrors('domain'); // ещё на хранении
    }
}
