<?php

namespace Tests\Feature\Web;

use App\Domain\Notifications\SmscGateway;
use App\Domain\Storage\ContractConfirmation;
use App\Domain\Users\Role;
use App\Models\ContractSmsCode;
use App\Models\Movement;
use App\Models\Setting;
use App\Models\SmsMessage;
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

        $this->put('/admin/settings/sms', ['auth' => 'password', 'login' => 'diskimira', 'password' => 'p1', 'sender' => 'DiskiMira',
            'confirm_contracts' => true, 'confirm_text' => 'Без кода'])->assertSessionHasErrors('confirm_text');
        $this->put('/admin/settings/sms', ['auth' => 'password', 'login' => 'diskimira', 'password' => 'p1', 'sender' => 'DiskiMira',
            'confirm_contracts' => true, 'confirm_text' => 'Ваш код {code}'])->assertSessionHasNoErrors();
        $this->assertSame('p1', app(SmscGateway::class)->password());
        $this->assertSame('Ваш код {code}', ContractConfirmation::text());

        // Пустой пароль не стирает сохранённый
        $this->put('/admin/settings/sms', ['auth' => 'password', 'login' => 'diskimira', 'password' => '', 'sender' => '', 'confirm_contracts' => true,
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

    public function test_apikey_auth_sends_apikey_instead_of_login_and_password(): void
    {
        $this->actingAs($this->user(Role::Admin));
        // Сохранены и логин/пароль, и ключ — выбор в настройках решает, что уходит в SMSC
        $this->put('/admin/settings/sms', ['auth' => 'apikey', 'apikey' => 'KEY-12345678', 'login' => 'diskimira', 'password' => 'p1',
            'sender' => '', 'confirm_contracts' => true, 'confirm_text' => ContractConfirmation::DEFAULT_TEXT])->assertSessionHasNoErrors();
        $raw = DB::table('settings')->where('key', SmscGateway::API_KEY)->value('value');
        $this->assertStringNotContainsString('KEY-12345678', $raw);
        $this->get('/admin/settings')->assertInertia(fn (Assert $p) => $p->where('sms.auth', 'apikey')->where('sms.configured', true)
            ->where('sms.apikey_set', true)->where('sms.apikey_mask', 'KEY-…5678'));

        Http::fake([self::SEND => Http::response(['id' => 9, 'cnt' => 1])]);
        $contract = $this->acceptViaForm();
        Http::assertSent(fn (Request $r) => $r['apikey'] === 'KEY-12345678' && ! isset($r['login']) && ! isset($r['psw'])
            && str_contains($r['mes'], $contract->number));

        // Переключение обратно на логин/пароль
        Setting::put(SmscGateway::AUTH, SmscGateway::AUTH_PASSWORD);
        app(SmscGateway::class)->send('+79123456789', 'тест');
        Http::assertSent(fn (Request $r) => $r['mes'] === 'тест' && $r['login'] === 'diskimira' && $r['psw'] === 'p1' && ! isset($r['apikey']));

        // Выбран ключ, но ключа нет — SMSC не настроен
        Setting::put(SmscGateway::AUTH, SmscGateway::AUTH_APIKEY);
        Setting::putSecret(SmscGateway::API_KEY, null);
        $this->assertFalse(app(SmscGateway::class)->configured());
    }

    public function test_sms_sending_delivery_status_and_signing_are_in_movement_history(): void
    {
        $this->configureSmsc();
        Http::fake([
            self::SEND => Http::response(['id' => 501, 'cnt' => 1]),
            'https://smsc.ru/sys/status.php' => Http::sequence()
                ->push(['status' => 0, 'last_date' => '05.11.2026 20:00:00', 'last_timestamp' => now()->timestamp, 'err' => 0])
                ->push(['status' => 1, 'last_date' => '05.11.2026 20:00:05', 'last_timestamp' => now()->timestamp, 'err' => 0]),
        ]);
        $contract = $this->acceptViaForm();
        $code = $this->lastCode();
        $set = $contract->sets()->first();

        $sms = SmsMessage::firstOrFail();
        $this->assertSame('501', $sms->provider_id);
        $this->assertSame('code', $sms->kind);
        $this->assertFalse($sms->final);
        $sent = Movement::where('wheel_set_id', $set->id)->where('action', 'sms_sent')->firstOrFail();
        $this->assertStringContainsString('+79123456789', $sent->note);
        $this->assertStringNotContainsString($code, $sent->note); // код в журнал не пишется

        // Передано оператору — не окончательный статус, в историю не пишется
        $this->artisan('sms:check-status')->assertSuccessful();
        $this->assertSame(0, $sms->fresh()->status);
        $this->assertFalse(Movement::where('action', 'sms_delivered')->exists());

        // Доставлено — запись в истории, дальше не опрашиваем
        $this->travel(1)->minutes();
        $this->artisan('sms:check-status')->assertSuccessful();
        $this->assertTrue($sms->fresh()->final);
        $this->assertStringContainsString('доставлено', Movement::where('action', 'sms_delivered')->firstOrFail()->note);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'status.php') && $r['id'] === '501' && $r['phone'] === '79123456789');
        $this->travel(1)->minutes();
        $this->artisan('sms:check-status')->assertSuccessful();
        Http::assertSentCount(3); // send + 2 status

        $wrong = $code === '0000' ? '1111' : '0000';
        $this->post("/contracts/{$contract->id}/confirm", ['code' => $wrong]);
        $this->post("/contracts/{$contract->id}/confirm", ['code' => $code])->assertSessionHasNoErrors();
        $this->assertTrue(Movement::where('action', 'sms_code_wrong')->exists());
        $this->assertStringContainsString($contract->number, Movement::where('action', 'contract_signed')->firstOrFail()->note);

        $this->get("/contracts/{$contract->id}")->assertInertia(fn (Assert $p) => $p
            ->where('movements.0.action', 'contract_signed')
            ->where('movements.0.action_label', 'Договор подписан кодом из SMS')
            ->where('confirmation.log.0.delivery', 'доставлено'));
    }

    public function test_undelivered_status_and_send_error_are_logged(): void
    {
        $this->configureSmsc();
        Http::fake([
            self::SEND => Http::sequence()->push(['error' => 'no money', 'error_code' => 3])->push(['id' => 77, 'cnt' => 1]),
            'https://smsc.ru/sys/status.php' => Http::response(['status' => 20, 'last_timestamp' => now()->timestamp, 'err' => 254]),
        ]);
        $contract = $this->acceptViaForm();
        $this->assertStringContainsString('недостаточно средств', Movement::where('action', 'sms_failed')->firstOrFail()->note);

        $this->post("/contracts/{$contract->id}/sms-code")->assertSessionHasNoErrors();
        // Статус подтягивается и при открытии карточки договора
        $this->get("/contracts/{$contract->id}")->assertOk();
        $note = Movement::where('action', 'sms_undelivered')->firstOrFail()->note;
        $this->assertStringContainsString('невозможно доставить', $note);
        $this->assertStringContainsString('254', $note);
    }

    private function assertSessionHasNoErrorFlash(): void
    {
        $this->assertNull(session('error'), (string) session('error'));
    }
}
