<?php

namespace Tests\Feature\Web;

use App\Domain\Notifications\SmscGateway;
use App\Domain\Storage\ContractConfirmation;
use App\Domain\Users\Role;
use App\Models\ContractSmsCode;
use App\Models\Setting;
use App\Models\StorageContract;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContractSmsConfirmationTest extends TestCase
{
    private const SEND = 'https://smsc.ru/sys/send.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('sms.sent', collect());
    }

    private function configureSmsc(): void
    {
        Setting::put(SmscGateway::LOGIN, 'diskimira');
        Setting::putSecret(SmscGateway::PASSWORD, 'secret-psw');
        Setting::put(SmscGateway::SENDER, 'DiskiMira');
    }

    /** Код из последнего «отправленного» SMS. */
    private function lastCode(): string
    {
        preg_match('/Код (\d{4})/u', app('sms.sent')->last()['text'], $m);

        return $m[1];
    }

    private function acceptViaForm(): StorageContract
    {
        $store = $this->store();
        $this->actingAs($this->user(Role::Seller, $store))->post('/accept', $this->acceptData($store))->assertRedirect();

        return StorageContract::firstOrFail();
    }

    public function test_password_is_stored_encrypted(): void
    {
        $this->configureSmsc();
        $raw = DB::table('settings')->where('key', SmscGateway::PASSWORD)->value('value');
        $this->assertStringNotContainsString('secret-psw', $raw);
        $this->assertSame('secret-psw', app(SmscGateway::class)->password());
    }

    public function test_code_is_sent_via_smsc_on_contract_creation_and_confirms_contract(): void
    {
        $this->configureSmsc();
        Http::fake([self::SEND => Http::response(['id' => 501, 'cnt' => 1])]);

        $contract = $this->acceptViaForm();
        $this->assertSessionHasNoErrorFlash();

        Http::assertSent(function (Request $r) use ($contract) {
            return $r->url() === self::SEND && $r['login'] === 'diskimira' && $r['psw'] === 'secret-psw'
                && $r['phones'] === '79123456789' && $r['sender'] === 'DiskiMira' && (int) $r['fmt'] === 3
                && $r['charset'] === 'utf-8' && str_contains($r['mes'], $contract->number);
        });
        $sent = ContractSmsCode::firstOrFail();
        $this->assertSame('501', $sent->provider_id);
        $this->assertNull($sent->error);
        $code = $this->lastCode();
        $this->assertNotSame($code, $sent->code_hash);

        $this->get("/contracts/{$contract->id}")->assertInertia(fn (Assert $p) => $p->component('Contracts/Show')
            ->where('confirmation.enabled', true)->where('confirmation.confirmed_at', null)->where('confirmation.phone', '+79123456789'));

        $wrong = $code === '0000' ? '1111' : '0000';
        $this->post("/contracts/{$contract->id}/confirm", ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertSame(1, $sent->fresh()->attempts); // попытка сохранена, несмотря на ошибку
        $this->assertNull($contract->fresh()->sms_confirmed_at);

        $this->post("/contracts/{$contract->id}/confirm", ['code' => $code])->assertSessionHasNoErrors()->assertSessionHas('success');
        $contract->refresh();
        $this->assertNotNull($contract->sms_confirmed_at);
        $this->assertSame('+79123456789', $contract->sms_confirmed_phone);
        $this->assertNotNull($contract->sms_confirmed_by);

        // Уже подтверждён — повторная отправка запрещена
        $this->post("/contracts/{$contract->id}/sms-code")->assertSessionHasErrors('domain');
    }

    public function test_attempts_limit_expiry_and_resend_cooldown(): void
    {
        $this->configureSmsc();
        Http::fake([self::SEND => Http::response(['id' => 1, 'cnt' => 1])]);
        $contract = $this->acceptViaForm();
        $code = $this->lastCode();
        $wrong = $code === '0000' ? '1111' : '0000';

        for ($i = 0; $i < ContractConfirmation::MAX_ATTEMPTS; $i++) {
            $this->post("/contracts/{$contract->id}/confirm", ['code' => $wrong])->assertSessionHasErrors('code');
        }
        // Даже верный код после исчерпания попыток не принимается
        $this->post("/contracts/{$contract->id}/confirm", ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull($contract->fresh()->sms_confirmed_at);

        // Повтор отправки не чаще раза в минуту
        $this->post("/contracts/{$contract->id}/sms-code")->assertSessionHasErrors('domain');
        $this->travel(61)->seconds();
        $this->post("/contracts/{$contract->id}/sms-code")->assertSessionHasNoErrors();
        $this->assertSame(2, ContractSmsCode::count());
        $newCode = $this->lastCode();

        // Код живёт 10 минут
        $this->travel(ContractConfirmation::TTL_MINUTES + 1)->minutes();
        $this->post("/contracts/{$contract->id}/confirm", ['code' => $newCode])->assertSessionHasErrors('code');

        $this->travel(1)->minutes();
        $this->post("/contracts/{$contract->id}/sms-code")->assertSessionHasNoErrors();
        $this->post("/contracts/{$contract->id}/confirm", ['code' => $this->lastCode()])->assertSessionHasNoErrors();
        $this->assertNotNull($contract->fresh()->sms_confirmed_at);
    }

    public function test_gateway_error_does_not_block_contract(): void
    {
        $this->configureSmsc();
        Http::fake([self::SEND => Http::sequence()
            ->push(['error' => 'no money', 'error_code' => 3])
            ->push(['id' => 7, 'cnt' => 1])]);

        $contract = $this->acceptViaForm();
        $this->assertNotNull($contract);
        $this->assertStringContainsString('недостаточно средств', session('error'));
        $this->assertStringContainsString('недостаточно средств', ContractSmsCode::firstOrFail()->error);

        // Неотправленный код не считается для паузы повтора — можно сразу отправить снова
        $this->post("/contracts/{$contract->id}/sms-code")->assertSessionHasNoErrors()->assertSessionHas('success');
    }

    public function test_no_sms_when_disabled_or_not_configured(): void
    {
        Http::fake();
        $this->acceptViaForm();
        $this->assertSame(0, ContractSmsCode::count());

        $this->configureSmsc();
        Setting::put(ContractConfirmation::ENABLED, false);
        $store = $this->store('М05');
        $this->actingAs($this->user(Role::Admin))->post('/accept', $this->acceptData($store, ['client' => ['phone' => '89990001122']]))->assertRedirect();
        $this->assertSame(0, ContractSmsCode::count());
        Http::assertNothingSent();
    }

    public function test_admin_saves_sms_settings_and_checks_balance(): void
    {
        $this->actingAs($this->user(Role::Admin));
        $this->get('/admin/settings')->assertInertia(fn (Assert $p) => $p->where('sms.configured', false)->where('sms.confirm_contracts', true));

        $this->put('/admin/settings/sms', ['login' => 'diskimira', 'password' => 'p1', 'sender' => 'DiskiMira',
            'confirm_contracts' => true, 'confirm_text' => 'Без кода'])->assertSessionHasErrors('confirm_text');
        $this->put('/admin/settings/sms', ['login' => 'diskimira', 'password' => 'p1', 'sender' => 'DiskiMira',
            'confirm_contracts' => true, 'confirm_text' => 'Ваш код {code}'])->assertSessionHasNoErrors();
        $this->assertSame('p1', app(SmscGateway::class)->password());
        $this->assertSame('Ваш код {code}', ContractConfirmation::text());

        // Пустой пароль не стирает сохранённый
        $this->put('/admin/settings/sms', ['login' => 'diskimira', 'password' => '', 'sender' => '', 'confirm_contracts' => true,
            'confirm_text' => ContractConfirmation::DEFAULT_TEXT])->assertSessionHasNoErrors();
        $this->assertSame('p1', app(SmscGateway::class)->password());
        $this->assertNull(app(SmscGateway::class)->sender());

        Http::fake(['https://smsc.ru/sys/balance.php' => Http::sequence()
            ->push(['balance' => '512.30', 'currency' => 'RUR'])
            ->push(['error' => 'auth', 'error_code' => 2])]);
        $this->post('/admin/settings/sms/test')->assertSessionHas('success', fn ($m) => str_contains($m, '512.30'));

        $this->post('/admin/settings/sms/test')->assertSessionHas('error', fn ($m) => str_contains($m, 'неверный логин'));

        $this->actingAs($this->user(Role::Seller))->put('/admin/settings/sms', [])->assertForbidden();
    }

    private function assertSessionHasNoErrorFlash(): void
    {
        $this->assertNull(session('error'), (string) session('error'));
    }
}
