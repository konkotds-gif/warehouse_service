<?php

namespace Tests\Feature\Web;

use App\Domain\Notifications\SmscGateway;
use App\Domain\Storage\ContractConfirmation;
use App\Domain\Storage\ContractStatus;
use App\Domain\Storage\IssueService;
use App\Domain\Storage\Pricing;
use App\Domain\Storage\SetStatus;
use App\Domain\Users\Role;
use App\Models\ContractSmsCode;
use App\Models\Movement;
use App\Models\OutboxEvent;
use App\Models\Setting;
use App\Models\StorageContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContractClosingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('sms.sent', collect());
        Setting::put(SmscGateway::LOGIN, 'diskimira');
        Setting::putSecret(SmscGateway::PASSWORD, 'secret-psw');
        Http::fake([
            'https://smsc.ru/sys/send.php' => Http::response(['id' => 1, 'cnt' => 1]),
            'https://smsc.ru/*' => Http::response(['status' => 1]),
        ]);
        Pricing::saveSettings(['free_days' => 7, 'overdue_rate' => 30, 'overdue_per' => 'contract']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lastSms(): string
    {
        return app('sms.sent')->last()['text'];
    }

    private function lastCode(): string
    {
        preg_match('/Код (\d{4})/u', $this->lastSms(), $m);

        return $m[1];
    }

    /** Договор на 1 месяц, комплект заказан к выдаче в магазине приёма (сразу «Готов к выдаче»). */
    private function readyContract(): array
    {
        $store = $this->store();
        $seller = $this->user(Role::Seller, $store);
        $contract = $this->accept($store, ['months' => 1], $seller);
        $contract->update(['debt_updated_at' => now()]);
        app(IssueService::class)->order($this->firstSet($contract), $store);

        return [$contract->fresh(), $seller, $store];
    }

    public function test_close_with_sms_code_charges_overdue_by_tariff(): void
    {
        [$contract, $seller] = $this->readyContract();
        $set = $this->firstSet($contract);
        Carbon::setTestNow($contract->storage_until->copy()->addDays(10)->setTime(12, 0));
        $this->actingAs($seller);

        $this->get("/contracts/{$contract->id}")->assertInertia(fn (Assert $p) => $p->component('Contracts/Show')
            ->where('closing.can_close', true)->where('closing.requires_code', true)
            ->where('closing.due', 90)->where('closing.overdue.days', 3)->has('closing.to_issue', 1));

        // Последний комплект нельзя выдать в обход закрытия по SMS
        $this->post("/sets/{$set->id}/issue", ['code' => $contract->number])->assertSessionHasErrors('domain');
        $this->assertSame(SetStatus::Ready, $set->fresh()->status);

        $this->post("/contracts/{$contract->id}/close-code")->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertStringContainsString("закрытие договора хранения № {$contract->number}", $this->lastSms());
        $this->assertStringContainsString('доплата за хранение 90 руб.', $this->lastSms());
        $this->assertSame(1, ContractSmsCode::where('purpose', ContractConfirmation::CLOSE)->count());
        $code = $this->lastCode();
        $wrong = $code === '0000' ? '1111' : '0000';

        // Без отметки об оплате просрочки не закрываем
        $this->post("/contracts/{$contract->id}/close", ['code' => $code, 'tag' => $contract->number])->assertSessionHasErrors('paid');
        // Неверный код: попытка сохраняется
        $this->post("/contracts/{$contract->id}/close", ['code' => $wrong, 'tag' => $contract->number, 'paid' => 1])->assertSessionHasErrors('code');
        $this->assertSame(1, ContractSmsCode::where('purpose', 'close')->first()->attempts);
        $this->assertSame(ContractStatus::Open, $contract->fresh()->status);

        $this->post("/contracts/{$contract->id}/close", ['code' => $code, 'tag' => $contract->number, 'paid' => 1])
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, '90,00'));

        $contract->refresh();
        $this->assertSame(ContractStatus::Closed, $contract->status);
        $this->assertSame('sms', $contract->close_confirmation);
        $this->assertSame('+79123456789', $contract->close_phone);
        $this->assertSame($seller->id, $contract->closed_by);
        $this->assertEquals(90, (float) $contract->overdue_fee);
        $this->assertSame(3, $contract->overdue_days);
        $this->assertSame(SetStatus::Issued, $set->fresh()->status);
        // Код закрытия не подтверждает подписание договора
        $this->assertNull($contract->sms_confirmed_at);

        $this->assertEquals(90, OutboxEvent::where('event_type', 'set.issued')->first()->payload['overdue']['amount']);
        $closed = OutboxEvent::where('event_type', 'contract.closed')->firstOrFail()->payload;
        $this->assertSame('sms', $closed['close_confirmation']);
        $this->assertEquals(90, $closed['overdue_fee']);
        $this->assertTrue(Movement::where('wheel_set_id', $set->id)->where('action', 'contract_closed')->exists());

        $this->post("/contracts/{$contract->id}/close-code")->assertSessionHasErrors('domain');
    }

    public function test_cannot_close_while_sets_are_not_ready(): void
    {
        $store = $this->store();
        $seller = $this->user(Role::Seller, $store);
        $contract = $this->accept($store, [], $seller);
        $this->actingAs($seller);

        $this->get("/contracts/{$contract->id}")->assertInertia(fn (Assert $p) => $p->where('closing.can_close', false)
            ->where('closing.blockers.0', fn ($b) => str_contains($b, 'закажите его к выдаче')));
        $this->post("/contracts/{$contract->id}/close-code")->assertSessionHasErrors('domain');
        $this->post("/contracts/{$contract->id}/close", ['code' => '1234', 'tag' => $contract->number])->assertSessionHasErrors('domain');
        $this->assertSame(ContractStatus::Open, $contract->fresh()->status);
    }

    public function test_manager_can_close_without_code_with_reason_seller_cannot(): void
    {
        [$contract, $seller, $store] = $this->readyContract();

        $this->actingAs($seller)->post("/contracts/{$contract->id}/close", ['tag' => $contract->number, 'reason' => 'SMS не доходит'])
            ->assertSessionHasErrors('domain');
        $this->assertSame(ContractStatus::Open, $contract->fresh()->status);

        $manager = $this->user(Role::StoreManager, $store);
        $this->actingAs($manager)->post("/contracts/{$contract->id}/close", ['tag' => $contract->number, 'reason' => 'SMS не доходит'])
            ->assertSessionHasNoErrors();
        $contract->refresh();
        $this->assertSame(ContractStatus::Closed, $contract->status);
        $this->assertSame('manual', $contract->close_confirmation);
        $this->assertSame('SMS не доходит', $contract->close_note);
    }

    public function test_without_sms_contract_closes_on_last_issue_as_before(): void
    {
        Setting::put(ContractConfirmation::CLOSE_ENABLED, false);
        [$contract, $seller] = $this->readyContract();
        $set = $this->firstSet($contract);

        $this->actingAs($seller)->get("/contracts/{$contract->id}")->assertInertia(fn (Assert $p) => $p->where('closing.requires_code', false));
        $this->post("/sets/{$set->id}/issue", ['code' => $contract->number])->assertSessionHasNoErrors();
        $contract->refresh();
        $this->assertSame(ContractStatus::Closed, $contract->status);
        $this->assertSame('none', $contract->close_confirmation);
        $this->assertSame($seller->id, $contract->closed_by);
    }

    public function test_wrong_tag_is_rejected_and_nothing_closes(): void
    {
        [$contract, $seller] = $this->readyContract();
        $this->actingAs($seller)->post("/contracts/{$contract->id}/close-code");
        $code = $this->lastCode();

        $this->post("/contracts/{$contract->id}/close", ['code' => $code, 'tag' => '999999'])->assertSessionHasErrors('domain');
        $this->assertSame(ContractStatus::Open, $contract->fresh()->status);
        // Код не погашен — после исправления скана закрытие проходит тем же кодом
        $this->post("/contracts/{$contract->id}/close", ['code' => $code, 'tag' => $contract->number])->assertSessionHasNoErrors();
        $this->assertSame(ContractStatus::Closed, StorageContract::find($contract->id)->status);
    }
}
