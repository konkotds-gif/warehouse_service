<?php

namespace Tests\Feature\Web;

use App\Domain\Users\Role;
use App\Models\ApiKey;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Администрирование → Ключи API: правка IP, отказ с увиденным IP, «Разрешить этот IP». */
class ApiKeysAdminTest extends TestCase
{
    public function test_denied_ip_is_shown_logged_and_can_be_allowed(): void
    {
        [$key, $plain] = ApiKey::generate('Онлайн-запись', '203.0.113.10', 'booking');
        $h = ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];

        // За NAT хранение видит другой адрес — он есть в ответе
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->getJson('/api/v1/booking/lookup?phone=89123456789', $h)
            ->assertStatus(403)->assertJsonPath('message', 'Доступ с этого IP запрещён (IP: 192.168.1.1)');
        $this->assertDatabaseHas('api_access_log', ['api_key_id' => $key->id, 'action' => 'denied_ip', 'ip' => '192.168.1.1']);

        $admin = $this->user(Role::Admin);
        $this->actingAs($admin)->get('/admin/api-keys')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Admin/ApiKeys')->where('log.0.ip', '192.168.1.1')->where('log.0.denied', true));

        $this->actingAs($admin)->post("/admin/api-keys/{$key->id}/allow-ip", ['ip' => '192.168.1.1'])->assertRedirect();
        $this->assertSame('203.0.113.10, 192.168.1.1', $key->fresh()->allowed_ips);

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->getJson('/api/v1/booking/lookup?phone=89123456789', $h)->assertOk();
    }

    public function test_admin_edits_key_ips_scope_and_toggles(): void
    {
        [$key] = ApiKey::generate('1С УТ', '10.0.0.5');
        $admin = $this->user(Role::Admin);

        $this->actingAs($admin)->put("/admin/api-keys/{$key->id}", ['name' => 'Запись', 'allowed_ips' => 'bad-ip', 'scope' => 'booking'])
            ->assertSessionHasErrors('allowed_ips');
        $this->actingAs($admin)->put("/admin/api-keys/{$key->id}", ['name' => 'Запись', 'allowed_ips' => '10.0.0.0/24; 198.51.100.7', 'scope' => 'booking'])
            ->assertSessionHasNoErrors();
        $key->refresh();
        $this->assertSame('10.0.0.0/24, 198.51.100.7', $key->allowed_ips);
        $this->assertSame(['booking'], $key->scopes);
        $this->assertTrue($key->allowsIp('10.0.0.77'));
        $this->assertFalse($key->allowsIp('10.0.1.1'));

        $this->actingAs($admin)->post("/admin/api-keys/{$key->id}/toggle")->assertRedirect();
        $this->assertFalse($key->fresh()->is_active);
        $this->actingAs($admin)->post("/admin/api-keys/{$key->id}/toggle");
        $this->assertTrue($key->fresh()->is_active);

        $this->actingAs($admin)->post('/admin/api-keys', ['name' => 'Онлайн-запись', 'allowed_ips' => '', 'scope' => 'booking'])
            ->assertSessionHas('secret');
        $this->assertNull(ApiKey::where('name', 'Онлайн-запись')->value('allowed_ips'));
    }

    public function test_only_admin_and_repeated_denials_are_throttled(): void
    {
        $this->actingAs($this->user(Role::Seller))->get('/admin/api-keys')->assertForbidden();

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/booking/lookup?phone=89123456789', ['Authorization' => 'Bearer wk_wrong'])->assertStatus(401);
        }
        $this->assertSame(1, DB::table('api_access_log')->where('action', 'denied_key')->count());
    }
}
