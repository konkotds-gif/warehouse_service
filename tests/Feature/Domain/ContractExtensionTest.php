<?php

namespace Tests\Feature\Domain;

use App\Domain\Storage\ContractService;
use App\Domain\Storage\ContractStatus;
use App\Domain\Users\Role;
use App\Models\ApiKey;
use App\Models\ContractExtension;
use App\Models\OutboxEvent;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContractExtensionTest extends TestCase
{
    public function test_extend_creates_prolongation_document_with_price_lines(): void
    {
        Carbon::setTestNow('2026-11-05 10:00:00');
        $contract = $this->accept($this->store(), ['months' => 1]);
        $before = $contract->fresh()->storage_until->toDateString();

        $e = app(ContractService::class)->extend($contract, 2);

        $this->assertSame('ПР00001', $e->number);
        $this->assertSame(1, $e->seq);
        $this->assertSame(2, $e->months);
        $this->assertSame($before, $e->previous_until->toDateString());
        $this->assertSame($before, $e->period_from->toDateString(), 'срок ещё не истёк — продлеваем от его окончания');
        $this->assertSame(Carbon::parse($before)->addMonthsNoOverflow(2)->toDateString(), $e->storage_until->toDateString());
        $this->assertSame($e->storage_until->toDateString(), $contract->fresh()->storage_until->toDateString());
        $this->assertEquals(960, (float) $e->amount, 'R16: 120 × 4 × 2');
        $this->assertSame('ЦБ000335', $e->lines[0]['service_code']);
        $this->assertStringContainsString('R16', $e->lines[0]['tire_size']);

        $event = OutboxEvent::where('event_type', 'contract.extended')->firstOrFail();
        $this->assertSame($event->id, $e->outbox_event_id);
        $this->assertSame('ПР00001', $event->payload['extension']['number']);
        $this->assertSame($e->storage_until->toDateString(), $event->payload['extension']['storage_until']);

        $second = app(ContractService::class)->extend($contract, 1);
        $this->assertSame(['ПР00002', 2], [$second->number, $second->seq]);
        $this->assertSame($e->storage_until->toDateString(), $second->previous_until->toDateString());
        Carbon::setTestNow();
    }

    public function test_overdue_contract_is_extended_from_today(): void
    {
        Carbon::setTestNow('2026-11-05 10:00:00');
        $contract = $this->accept($this->store(), ['months' => 1]);
        $contract->update(['storage_until' => '2026-10-01', 'status' => ContractStatus::Overdue]);

        $e = app(ContractService::class)->extend($contract->fresh(), 1);
        $this->assertSame('2026-10-01', $e->previous_until->toDateString());
        $this->assertSame('2026-11-05', $e->period_from->toDateString());
        $this->assertSame('2026-12-05', $e->storage_until->toDateString());
        $this->assertSame(ContractStatus::Open, $contract->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_onec_ack_binds_document_guid_to_extension_not_contract(): void
    {
        [, $key] = ApiKey::generate('1С');
        $contract = $this->accept($this->store());
        $e = app(ContractService::class)->extend($contract, 1);
        $guid = 'b0000000-0000-0000-0000-000000000003';

        $this->postJson('/api/v1/integration/ack', ['event_id' => $e->outbox_event_id, 'onec_doc_guid' => $guid],
            ['X-Api-Key' => $key, 'Accept' => 'application/json'])->assertOk();

        $this->assertSame($guid, $e->fresh()->onec_doc_guid);
        $this->assertNull($contract->fresh()->onec_doc_guid);

        $this->getJson('/api/v1/contracts/'.$contract->number, ['X-Api-Key' => $key, 'Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('extensions.0.number', 'ПР00001')->assertJsonPath('extensions.0.onec_doc_guid', $guid);
    }

    public function test_web_extend_shows_history_and_prints_document(): void
    {
        $store = $this->store();
        $contract = $this->accept($store);
        $this->actingAs($this->user(Role::Seller, $store));

        $this->post("/contracts/{$contract->id}/extend", ['months' => 3])->assertRedirect()->assertSessionHas('success');
        $e = ContractExtension::firstOrFail();

        $this->get('/contracts/'.$contract->id)->assertInertia(fn (Assert $p) => $p
            ->component('Contracts/Show')
            ->where('extensions.0.number', 'ПР00001')
            ->where('extensions.0.months', 3)
            ->has('pricing.extend_from'));

        $this->get("/contracts/{$contract->id}/extensions/{$e->id}/print")->assertOk()
            ->assertSee('Пролонгация договора хранения № ПР00001', false)
            ->assertSee('ЦБ000335')
            ->assertSee($e->storage_until->format('d.m.Y'));

        $other = $this->accept($store);
        $this->get("/contracts/{$other->id}/extensions/{$e->id}/print")->assertNotFound();
    }
}
