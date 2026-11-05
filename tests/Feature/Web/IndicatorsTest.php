<?php

namespace Tests\Feature\Web;

use App\Domain\Transfer\TransferService;
use App\Domain\Users\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IndicatorsTest extends TestCase
{
    /** @return array<string, int> key → count */
    private function counts(User $user): array
    {
        return collect($this->actingAs($user)->getJson('/indicators')->assertOk()->json())->pluck('count', 'key')->all();
    }

    public function test_indicators_follow_the_set_through_store_and_warehouse(): void
    {
        $store = $this->store();
        $wh = $this->warehouse();
        $seller = $this->user(Role::Seller, $store);
        $keeper = $this->user(Role::Storekeeper, $wh);
        $set = $this->firstSet($this->accept($store));
        $this->accept($store, ['client' => ['phone' => '8 (912) 000-00-01'], 'vehicle' => ['plate' => 'в777ор 86']]);

        // Продавец: два комплекта в буфере, не отправлены; задания склада ему не показываются
        $c = $this->counts($seller);
        $this->assertSame(2, $c['buffer']);
        $this->assertSame(0, $c['outbound']);
        $this->assertSame(0, $c['ready']);
        $this->assertArrayNotHasKey('tasks', $c);
        $this->assertArrayNotHasKey('to_place', $c);

        // Создали перемещение — комплект ушёл из «не отправлены» в «ждут погрузки»
        $svc = app(TransferService::class);
        $t = $svc->create($store, $wh, [$set->id]);
        $c = $this->counts($seller);
        $this->assertSame(1, $c['buffer']);
        $this->assertSame(1, $c['outbound']);
        $this->assertSame(0, $this->counts($keeper)['inbound']);

        // Отгрузили — у кладовщика склада «едут к нам»
        $svc->scanOut($t, $set->qr_code);
        $svc->ship($t);
        $this->assertSame(0, $this->counts($seller)['outbound']);
        $c = $this->counts($keeper);
        $this->assertSame(1, $c['inbound']);
        $this->assertSame(0, $c['to_place']);
        $this->assertArrayNotHasKey('buffer', $c);
        $this->assertArrayNotHasKey('ready', $c);

        $this->actingAs($keeper)->get('/transfers?status=shipped&dir=in')
            ->assertInertia(fn (Assert $p) => $p->component('Transfers/Index')->has('transfers.data', 1)->where('dir', 'in'));
        $this->get('/transfers?status=shipped&dir=out')
            ->assertInertia(fn (Assert $p) => $p->has('transfers.data', 0));

        // Приняли на складе — «ждут размещения»
        $svc->scanIn($t, $set->qr_code);
        $c = $this->counts($keeper);
        $this->assertSame(0, $c['inbound']);
        $this->assertSame(1, $c['to_place']);

        // Индикаторы приходят общим пропом на каждую страницу
        $this->get('/')->assertInertia(fn (Assert $p) => $p->component('Dashboard')
            ->where('indicators', fn ($items) => collect($items)->firstWhere('key', 'to_place')['count'] === 1)
            ->where('attention', null));
    }

    public function test_admin_sees_network_counters_and_dashboard_for_selected_point(): void
    {
        $store = $this->store();
        $this->warehouse();
        $this->accept($store);
        $admin = $this->user();

        $c = $this->counts($admin);
        $this->assertSame(1, $c['buffer']);
        $this->assertArrayHasKey('onec', $c);
        $this->assertArrayHasKey('tasks', $c);

        $this->get('/?location='.$store->id)->assertInertia(fn (Assert $p) => $p->component('Dashboard')
            ->where('attention', fn ($items) => collect($items)->firstWhere('key', 'buffer')['count'] === 1
                && collect($items)->firstWhere('key', 'to_place') === null));
    }

    public function test_guest_cannot_read_indicators(): void
    {
        $this->getJson('/indicators')->assertUnauthorized();
    }
}
