<?php

namespace Tests\Feature\Domain;

use App\Domain\Storage\ContractService;
use App\Domain\Storage\IssueService;
use App\Domain\Storage\Pricing;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\DomainError;
use App\Models\OutboxEvent;
use App\Models\Tariff;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PricingTest extends TestCase
{
    public function test_price_list_is_seeded_with_onec_codes(): void
    {
        $this->assertSame(9, Tariff::whereNotNull('diameter_from')->where('is_active', true)->count());
        $r16 = Tariff::where('code', 'ЦБ000335')->first();
        $this->assertEquals(120, (float) $r16->price);
        $this->assertSame([16, 16], [$r16->diameter_from, $r16->diameter_to]);
        $r21 = Tariff::where('code', 'ЦБ004139')->first();
        $this->assertSame([21, 22], [$r21->diameter_from, $r21->diameter_to]);
    }

    public function test_diameter_from_tire_size(): void
    {
        $this->assertSame(16, Pricing::diameter('205/55 R16 94H'));
        $this->assertSame(14, Pricing::diameter('185/75 R14C 102/100R'));
        $this->assertSame(18, Pricing::diameter('235/45 ZR18 98W'));
        $this->assertSame(15, Pricing::diameter('31x10.5 R15 109Q'));
        $this->assertSame(17, Pricing::diameter('215/75 R17.5 126M'));
        $this->assertNull(Pricing::diameter('мусор'));
    }

    public function test_amount_is_price_by_diameter_times_wheels_times_months_per_set(): void
    {
        $contract = $this->accept($this->store(), [
            'months' => 3,
            'sets' => [
                ['qty' => 4, 'tire_size' => '205/55 R16', 'with_rims' => true, 'condition' => 'used'],
                ['qty' => 2, 'tire_size' => '275/40 R22 107Y', 'with_rims' => false, 'condition' => 'new'],
            ],
        ]);
        // R16: 120 × 4 × 3 = 1440; R22 (строка R21–22): 200 × 2 × 3 = 1200
        $this->assertEquals(2640, (float) $contract->amount);
        $this->assertSame(3, $contract->months);
        $sets = $contract->sets()->orderBy('qr_code')->get();
        $this->assertEquals(1440, (float) $sets[0]->amount);
        $this->assertSame('ЦБ000335', $sets[0]->tariff->code);
        $this->assertSame('ЦБ004139', $sets[1]->tariff->code);

        $payload = OutboxEvent::where('event_type', 'contract.created')->first()->payload;
        $this->assertSame('ЦБ000335', $payload['sets'][0]['service_code']);
        $this->assertEquals(120, $payload['sets'][0]['price']);
        $this->assertSame(3, $payload['months']);
    }

    public function test_price_change_applies_to_new_contracts_only(): void
    {
        $old = $this->accept($this->store(), ['months' => 1]);
        Tariff::where('code', 'ЦБ000335')->update(['price' => 150]);
        $new = $this->accept($this->store('М05'), ['months' => 1, 'client' => ['phone' => '+79990000001'], 'vehicle' => ['plate' => 'В777ВВ86']]);
        $this->assertEquals(480, (float) $old->fresh()->amount);
        $this->assertEquals(600, (float) $new->amount);
    }

    public function test_size_without_price_and_months_over_limit_are_rejected(): void
    {
        try {
            $this->accept($this->store(), ['sets' => [['qty' => 4, 'tire_size' => '155/80 R12', 'with_rims' => true, 'condition' => 'used']]]);
            $this->fail('R12 нет в прайсе');
        } catch (DomainError $e) {
            $this->assertStringContainsString('R12', $e->getMessage());
        }
        $this->expectException(DomainError::class);
        $this->accept($this->store('М05'), ['months' => 8]);
    }

    public function test_overdue_after_free_days_per_contract_and_per_wheel(): void
    {
        Pricing::saveSettings(['free_days' => 5, 'overdue_rate' => 30, 'overdue_per' => 'contract']);
        $contract = $this->accept($this->store(), ['months' => 1]);
        $ends = $contract->storage_until->copy();
        $pricing = app(Pricing::class);

        $this->assertSame(0, $pricing->overdue($contract, $ends->copy()->addDays(5))['days'], 'бесплатные дни');
        $o = $pricing->overdue($contract, $ends->copy()->addDays(15));
        $this->assertSame(10, $o['days']);
        $this->assertEquals(300, $o['amount'], '10 дн. × 30 ₽ за договор');

        Pricing::saveSettings(['overdue_per' => 'wheel']);
        $this->assertEquals(1200, $pricing->overdue($contract, $ends->copy()->addDays(15))['amount'], '10 дн. × 30 ₽ × 4 колеса');
    }

    public function test_overdue_fee_is_shown_and_charged_on_issue(): void
    {
        Pricing::saveSettings(['free_days' => 7, 'overdue_rate' => 30, 'overdue_per' => 'contract']);
        $store = $this->store();
        $contract = $this->accept($store, ['months' => 1]);
        $set = $this->firstSet($contract);
        $contract->update(['debt_updated_at' => now()]);

        Carbon::setTestNow($contract->storage_until->copy()->addDays(10)->setTime(12, 0));
        $issue = app(IssueService::class);
        $check = $issue->debtCheck($contract->fresh());
        $this->assertEquals(90, $check['overdue']['due'], '3 платных дня × 30 ₽');
        $this->assertFalse($check['blocked'], 'просрочка не блокирует выдачу — показываем сумму');
        $this->assertStringContainsString('90', implode(' ', $check['warnings']));

        $issue->order($set, $store);
        $issue->issue($set->fresh(), $set->qr_code);
        $this->assertSame(SetStatus::Issued, $set->fresh()->status);
        $this->assertEquals(90, (float) $contract->fresh()->overdue_fee);
        $event = OutboxEvent::where('event_type', 'set.issued')->first();
        $this->assertEquals(90, $event->payload['overdue']['amount']);
        Carbon::setTestNow();
    }

    public function test_extend_uses_current_price_list(): void
    {
        $contract = $this->accept($this->store(), ['months' => 1]);
        app(ContractService::class)->extend($contract, 3);
        $this->assertEquals(480 + 1440, (float) $contract->fresh()->amount);
        $event = OutboxEvent::where('event_type', 'contract.extended')->first();
        $this->assertSame('ЦБ000335', $event->payload['extension_lines'][0]['service_code']);
    }
}
