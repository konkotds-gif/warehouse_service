<?php

namespace Tests\Feature\Web;

use App\Domain\Ldap\LdapConfig;
use App\Domain\Ldap\LdapConnection;
use App\Domain\Ldap\LdapDirectory;
use App\Domain\Users\Role;
use App\Models\LdapGroupRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeLdap;
use Tests\TestCase;

class LdapAuthTest extends TestCase
{
    private FakeLdap $ad;

    private string $sellers;

    private string $managers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ad = new FakeLdap;
        $this->app->instance(LdapConnection::class, $this->ad);
        $this->sellers = $this->ad->addGroup('WH-Sellers');
        $this->managers = $this->ad->addGroup('WH-Managers');
    }

    private function enable(array $hosts = ['dc1.corp.local']): void
    {
        (new LdapConfig(enabled: true, hosts: $hosts, port: 636, encryption: 'ldaps', baseDn: 'DC=corp,DC=local',
            bindUser: FakeLdap::SERVICE, domain: 'CORP'))->save();
        Setting::putSecret(LdapConfig::PASSWORD, FakeLdap::SERVICE_PASSWORD);
    }

    private function map(string $groupDn, Role $role, ?int $locationId = null): LdapGroupRole
    {
        return LdapGroupRole::create(['group_dn' => $groupDn, 'group_name' => 'g', 'role' => $role, 'location_id' => $locationId]);
    }

    public function test_ad_user_is_created_with_role_and_location_from_group(): void
    {
        $store = $this->store();
        $this->enable();
        $this->map($this->sellers, Role::Seller, $store->id);
        $this->ad->addUser('petrov', 'Pa$$w0rd', [$this->sellers]);

        $this->get('/login')->assertInertia(fn (Assert $p) => $p->component('Auth/Login')->where('ldap.domain', 'CORP'));
        $this->post('/login', ['login' => 'CORP\\petrov', 'password' => 'Pa$$w0rd'])->assertRedirect('/');

        $user = User::where('login', 'petrov')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->isLdap());
        $this->assertSame(Role::Seller, $user->role);
        $this->assertSame($store->id, $user->location_id);
        $this->assertSame('Petrov Тестовый', $user->name);
        $this->assertNotNull($user->ldap_guid);
    }

    public function test_role_is_reassigned_on_every_login_and_highest_role_wins(): void
    {
        $this->enable();
        $this->map($this->sellers, Role::Seller);
        $this->map($this->managers, Role::StoreManager);
        $this->ad->addUser('petrov', 'pw-12345', [$this->sellers]);

        $this->post('/login', ['login' => 'petrov', 'password' => 'pw-12345']);
        $this->assertSame(Role::Seller, User::where('login', 'petrov')->first()->role);
        $this->post('/logout');

        $this->ad->users['petrov']['groups'] = [$this->sellers, $this->managers];
        $this->post('/login', ['login' => 'petrov@corp.local', 'password' => 'pw-12345'])->assertRedirect('/');
        $this->assertSame(Role::StoreManager, User::where('login', 'petrov')->first()->role);
        $this->assertSame(1, User::where('login', 'petrov')->count());
    }

    public function test_nested_groups_are_taken_into_account(): void
    {
        $this->enable();
        $this->map($this->sellers, Role::Seller);
        $shop5 = $this->ad->addGroup('Shop-05', [$this->sellers]); // «Магазин 5» вложена в WH-Sellers
        $this->ad->addUser('sidorov', 'pw-12345', [$shop5]);

        $this->post('/login', ['login' => 'sidorov', 'password' => 'pw-12345'])->assertRedirect('/');
        $this->assertSame(Role::Seller, User::where('login', 'sidorov')->first()->role);
    }

    public function test_wrong_password_unknown_user_and_lockout(): void
    {
        $this->enable();
        $this->map($this->sellers, Role::Seller);
        $this->ad->addUser('petrov', 'pw-12345', [$this->sellers]);

        $this->post('/login', ['login' => 'petrov', 'password' => 'wrong'])->assertSessionHasErrors(['login' => 'Неверный логин или пароль']);
        $this->post('/login', ['login' => 'nobody', 'password' => 'x'])->assertSessionHasErrors(['login' => 'Неверный логин или пароль']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'petrov', 'password' => 'wrong']);
        }
        $this->post('/login', ['login' => 'petrov', 'password' => 'pw-12345'])->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->assertSame(0, User::where('login', 'petrov')->count());
    }

    public function test_user_without_mapped_group_or_disabled_cannot_login(): void
    {
        $this->enable();
        $this->map($this->sellers, Role::Seller);
        $this->ad->addUser('guest', 'pw-12345', [$this->ad->addGroup('Other')]);
        $this->ad->addUser('fired', 'pw-12345', [$this->sellers], ['uac' => 514]);

        $this->post('/login', ['login' => 'guest', 'password' => 'pw-12345'])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => 'fired', 'password' => 'pw-12345'])->assertSessionHasErrors(['login' => 'Учётная запись отключена в домене']);
        $this->assertGuest();
        $this->assertSame(0, User::whereIn('login', ['guest', 'fired'])->count());
    }

    public function test_local_admin_is_fallback_when_domain_controllers_are_down(): void
    {
        $this->enable(['dc1.corp.local', 'dc2.corp.local']);
        $this->map($this->sellers, Role::Seller);
        $this->ad->addUser('petrov', 'pw-12345', [$this->sellers]);
        $admin = User::factory()->create(['login' => 'admin', 'password' => 'local-pass']);

        // Первый DC недоступен — используется второй
        $this->ad->down = ['dc1.corp.local'];
        $this->post('/login', ['login' => 'petrov', 'password' => 'pw-12345'])->assertRedirect('/');
        $this->assertContains('dc2.corp.local', $this->ad->connectedTo);
        $this->post('/logout');

        // Оба недоступны: доменный вход — понятная ошибка, локальный admin входит, AD не опрашивается
        $this->ad->down = ['dc1.corp.local', 'dc2.corp.local'];
        $this->post('/login', ['login' => 'petrov', 'password' => 'pw-12345'])->assertSessionHasErrors('login');
        $this->ad->connectedTo = [];
        $this->post('/login', ['login' => 'admin', 'password' => 'local-pass'])->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
        $this->assertSame([], $this->ad->connectedTo);
    }

    public function test_ad_users_cannot_login_when_ad_is_disabled(): void
    {
        $this->enable();
        $this->map($this->sellers, Role::Seller);
        $this->ad->addUser('petrov', 'pw-12345', [$this->sellers]);
        $this->post('/login', ['login' => 'petrov', 'password' => 'pw-12345']);
        $this->post('/logout');

        $config = LdapConfig::load();
        $config->enabled = false;
        $config->save();
        $this->post('/login', ['login' => 'petrov', 'password' => 'pw-12345'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_sync_revokes_access_ends_session_and_restores_it(): void
    {
        $this->enable();
        $this->map($this->sellers, Role::Seller);
        $this->map($this->managers, Role::StoreManager);
        $this->ad->addUser('petrov', 'pw-12345', [$this->sellers]);
        $this->ad->addUser('ivanova', 'pw-12345', [$this->sellers]);
        $this->post('/login', ['login' => 'ivanova', 'password' => 'pw-12345']);
        $this->post('/logout');
        $this->post('/login', ['login' => 'petrov', 'password' => 'pw-12345']);
        $this->get('/')->assertOk();

        // petrov убран из групп, ivanova повышена
        $this->ad->users['petrov']['groups'] = [];
        $this->ad->users['ivanova']['groups'] = [$this->managers];
        $this->artisan('ldap:sync')->assertSuccessful();

        $petrov = User::where('login', 'petrov')->first();
        $this->assertNotNull($petrov->ldap_denied_at);
        $this->assertTrue($petrov->is_active); // ручная блокировка не тронута
        $this->assertSame(Role::StoreManager, User::where('login', 'ivanova')->first()->role);
        $this->app['auth']->forgetGuards(); // как в новом запросе: пользователь читается из базы заново
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();

        // вернули в группу — доступ возвращается при сверке
        $this->ad->users['petrov']['groups'] = [$this->sellers];
        $this->artisan('ldap:sync')->assertSuccessful();
        $this->assertNull($petrov->fresh()->ldap_denied_at);
        $this->assertSame('ok', Setting::get(LdapConfig::LAST_SYNC)['status']);
    }

    public function test_sync_changes_nothing_when_ad_is_unreachable(): void
    {
        $this->enable();
        $this->map($this->sellers, Role::Seller);
        $this->ad->addUser('petrov', 'pw-12345', [$this->sellers]);
        $this->post('/login', ['login' => 'petrov', 'password' => 'pw-12345']);

        $this->ad->down = ['dc1.corp.local'];
        $this->artisan('ldap:sync')->assertFailed();
        $this->assertNull(User::where('login', 'petrov')->first()->ldap_denied_at);
        $this->assertSame('error', Setting::get(LdapConfig::LAST_SYNC)['status']);
    }

    public function test_admin_blocks_ad_user_but_cannot_change_role(): void
    {
        $this->enable();
        $this->map($this->sellers, Role::Seller);
        $this->ad->addUser('petrov', 'pw-12345', [$this->sellers]);
        $this->post('/login', ['login' => 'petrov', 'password' => 'pw-12345']);
        $this->post('/logout');
        $petrov = User::where('login', 'petrov')->first();

        $this->actingAs($this->user())->put("/admin/users/{$petrov->id}", ['login' => 'x', 'role' => 'admin', 'is_active' => false])->assertSessionHasNoErrors();
        $petrov->refresh();
        $this->assertSame('petrov', $petrov->login);
        $this->assertSame(Role::Seller, $petrov->role);
        $this->assertFalse($petrov->is_active);
        $this->post('/logout');

        $this->post('/login', ['login' => 'petrov', 'password' => 'pw-12345'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_settings_save_password_encrypted_and_page_props(): void
    {
        $admin = $this->user();
        $this->actingAs($admin)->put('/admin/settings/ldap', [
            'enabled' => true, 'hosts' => 'ldaps://dc1.corp.local, dc2.corp.local', 'port' => 636, 'encryption' => 'ldaps', 'verify_cert' => true,
            'base_dn' => 'DC=corp,DC=local', 'bind_user' => FakeLdap::SERVICE, 'bind_password' => 'svc-secret', 'domain' => 'CORP', 'timeout' => 5,
        ])->assertSessionHasNoErrors();

        $config = LdapConfig::load();
        $this->assertTrue($config->enabled);
        $this->assertSame(['dc1.corp.local', 'dc2.corp.local'], $config->hosts);
        $this->assertSame('svc-secret', $config->bindPassword);
        $this->assertStringNotContainsString('svc-secret', DB::table('settings')->where('key', LdapConfig::PASSWORD)->value('value'));

        $this->get('/admin/settings')->assertInertia(fn (Assert $p) => $p->component('Admin/Settings')
            ->where('ldap.config.enabled', true)->where('ldap.password_set', true)->missing('ldap.config.bind_password'));
        $this->assertStringNotContainsString('svc-secret', $this->get('/admin/settings')->getContent());

        // включить без пароля нельзя
        Setting::putSecret(LdapConfig::PASSWORD, null);
        $this->put('/admin/settings/ldap', ['enabled' => true, 'hosts' => 'dc1', 'port' => 636, 'encryption' => 'ldaps', 'base_dn' => 'DC=corp,DC=local', 'bind_user' => 'svc'])
            ->assertSessionHasErrors('enabled');

        $seller = $this->user(Role::Seller);
        $this->actingAs($seller)->get('/admin/settings')->assertForbidden();
        $this->actingAs($seller)->post('/admin/settings/ldap/test', [])->assertForbidden();
    }

    public function test_connection_and_user_checks_and_group_search(): void
    {
        $this->enable();
        $this->map($this->managers, Role::StoreManager);
        $this->ad->addUser('petrov', 'pw-12345', [$this->managers]);
        $this->actingAs($this->user());
        $form = ['hosts' => 'dc1.corp.local', 'port' => 636, 'encryption' => 'ldaps', 'base_dn' => 'DC=corp,DC=local', 'bind_user' => FakeLdap::SERVICE];

        $this->postJson('/admin/settings/ldap/test', $form)->assertJson(['ok' => true]);
        $this->postJson('/admin/settings/ldap/test', [...$form, 'bind_password' => 'bad'])->assertJson(['ok' => false]);

        $this->postJson('/admin/settings/ldap/test-user', [...$form, 'login' => 'petrov'])
            ->assertJson(['ok' => true, 'role' => 'Руководитель магазина', 'groups' => [['name' => 'WH-Managers', 'role' => 'Руководитель магазина']]]);
        $this->postJson('/admin/settings/ldap/test-user', [...$form, 'login' => 'petrov', 'password' => 'wrong'])
            ->assertJson(['ok' => false, 'message' => 'Неверный логин или пароль']);
        $this->postJson('/admin/settings/ldap/test-user', [...$form, 'login' => 'nobody'])->assertJson(['ok' => false]);

        $this->getJson('/admin/settings/ldap/groups?q=sell')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'WH-Sellers');
    }

    public function test_mapping_crud(): void
    {
        $this->actingAs($this->user());
        $store = $this->store();
        $this->post('/admin/settings/ldap/mappings', ['group_dn' => $this->sellers, 'group_name' => 'WH-Sellers', 'role' => 'seller', 'location_id' => $store->id])
            ->assertSessionHasNoErrors();
        $this->post('/admin/settings/ldap/mappings', ['group_dn' => $this->sellers, 'group_name' => 'WH-Sellers', 'role' => 'driver'])
            ->assertSessionHasErrors('group_dn');
        $m = LdapGroupRole::firstOrFail();
        $this->put("/admin/settings/ldap/mappings/{$m->id}", ['group_dn' => $this->sellers, 'group_name' => 'WH-Sellers', 'role' => 'storekeeper'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Role::Storekeeper, $m->fresh()->role);
        $this->delete("/admin/settings/ldap/mappings/{$m->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, LdapGroupRole::count());
    }

    public function test_helpers(): void
    {
        $this->assertSame('ivanov', LdapDirectory::normalizeLogin(' CORP\\ivanov '));
        $this->assertSame('ivanov', LdapDirectory::normalizeLogin('ivanov@corp.local'));
        $this->assertSame('a\\2a\\28b\\29\\5c', LdapDirectory::escape('a*(b)\\'));

        $bin = hex2bin('d6b3a1f04c2e8a4b9f1e0123456789ab');
        $guid = LdapDirectory::guidToString($bin);
        $this->assertSame('f0a1b3d6-2e4c-4b8a-9f1e-0123456789ab', $guid);
        $this->assertSame('\\d6\\b3\\a1\\f0\\4c\\2e\\8a\\4b\\9f\\1e\\01\\23\\45\\67\\89\\ab', LdapDirectory::guidFilter($guid));

        $this->assertFalse(LdapDirectory::isExpired('0'));
        $this->assertFalse(LdapDirectory::isExpired('9223372036854775807'));
        $this->assertTrue(LdapDirectory::isExpired((string) ((time() - 86400 + 11644473600) * 10000000)));
        $this->assertFalse(LdapDirectory::isExpired((string) ((time() + 86400 + 11644473600) * 10000000)));
    }
}
