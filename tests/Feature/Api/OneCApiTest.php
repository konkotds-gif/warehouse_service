<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Client;
use App\Models\InboxRecord;
use App\Models\OutboxEvent;
use App\Models\StorageContract;
use App\Models\Tariff;
use Tests\TestCase;

class OneCApiTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->key] = ApiKey::generate('1С УТ');
    }

    private function h(array $extra = []): array
    {
        return $extra + ['X-Api-Key' => $this->key, 'Accept' => 'application/json'];
    }

    public function test_requires_api_key_and_ip_whitelist(): void
    {
        $this->getJson('/api/v1/stock')->assertStatus(401);
        $this->getJson('/api/v1/stock', ['X-Api-Key' => 'wrong'])->assertStatus(401);

        [, $limited] = ApiKey::generate('1С ограниченный', '10.0.0.5');
        $this->getJson('/api/v1/stock', ['X-Api-Key' => $limited])->assertStatus(403);
        $this->getJson('/api/v1/stock', $this->h())->assertOk();
    }

    public function test_events_cursor_ack_and_client_guid_binding(): void
    {
        $contract = $this->accept($this->store());
        $res = $this->getJson('/api/v1/integration/events?after=0', $this->h())->assertOk();
        $events = $res->json('events');
        $this->assertSame(['client.created', 'contract.created'], array_column($events, 'type'));
        $this->assertSame('000001', $events[1]['data']['contract_number']);
        $last = $res->json('last_id');

        $this->getJson('/api/v1/integration/events?after='.$last, $this->h())->assertOk()->assertJsonCount(0, 'events');

        $clientGuid = '8c1f0000-0000-0000-0000-000000000001';
        $docGuid = 'a77e0000-0000-0000-0000-000000000002';
        $this->postJson('/api/v1/integration/ack', ['events' => [
            ['event_id' => $events[0]['id'], 'client_onec_guid' => $clientGuid],
            ['event_id' => $events[1]['id'], 'onec_doc_guid' => $docGuid],
        ]], $this->h())->assertOk()->assertJsonPath('results.1.status', 'ok');

        $this->assertSame($clientGuid, Client::first()->onec_guid);
        $this->assertSame($docGuid, $contract->fresh()->onec_doc_guid);
        $this->assertSame(0, OutboxEvent::whereNull('delivered_at')->count());
    }

    public function test_xml_events_and_xml_payment_body(): void
    {
        $this->accept($this->store());
        $res = $this->get('/api/v1/integration/events?after=0', $this->h(['Accept' => 'application/xml']))->assertOk();
        $this->assertStringContainsString('application/xml', $res->headers->get('Content-Type'));
        $xml = simplexml_load_string($res->getContent());
        $this->assertSame('contract.created', (string) $xml->events->event[1]['type']);
        $this->assertSame('000001', (string) $xml->events->event[1]->contract_number);

        $body = '<payment><contract>000001</contract><amount>4800.00</amount><paid_until>'.now()->addMonths(6)->toDateString().'</paid_until></payment>';
        $this->call('POST', '/api/v1/integration/payments', [], [], [], $this->transformHeadersToServerVars(['X-Api-Key' => $this->key, 'Content-Type' => 'application/xml', 'Accept' => 'application/xml']), $body)
            ->assertStatus(201);
        $this->assertNotNull(StorageContract::first()->paid_until);
    }

    public function test_idempotency_key_replays_and_conflicts(): void
    {
        $this->accept($this->store());
        $h = $this->h(['Idempotency-Key' => 'pay-1']);
        $body = ['contract' => '000001', 'amount' => 100];
        $first = $this->postJson('/api/v1/integration/payments', $body, $h)->assertStatus(201);
        $second = $this->postJson('/api/v1/integration/payments', $body, $h)->assertStatus(201);
        $this->assertSame('true', $second->headers->get('Idempotent-Replay'));
        $this->assertSame($first->json('payment_id'), $second->json('payment_id'));
        $this->assertSame(1, StorageContract::first()->payments()->count());
        $this->assertSame(1, InboxRecord::count());

        $this->postJson('/api/v1/integration/payments', ['contract' => '000001', 'amount' => 999], $h)->assertStatus(409);
    }

    public function test_reference_upserts(): void
    {
        $guid = '11111111-0000-0000-0000-000000000001';
        $this->putJson("/api/v1/clients/{$guid}", ['full_name' => 'ООО Ромашка', 'phone' => '89120000000'], $this->h())
            ->assertStatus(201)->assertJsonPath('phone', '+79120000000');
        $this->putJson("/api/v1/clients/{$guid}", ['full_name' => 'ООО Ромашка 2'], $this->h())->assertOk();
        $this->assertSame(1, Client::count());

        $this->putJson('/api/v1/services/22222222-0000-0000-0000-000000000001', ['code' => 'ХР-1', 'name' => 'Хранение', 'price' => 4800], $this->h())
            ->assertStatus(201);
        $this->assertSame(1, Tariff::count());

        $this->store('М01');
        $this->putJson('/api/v1/locations/33333333-0000-0000-0000-000000000001', ['code' => 'М01'], $this->h())
            ->assertOk()->assertJsonPath('onec_guid', '33333333-0000-0000-0000-000000000001');
        $this->putJson('/api/v1/locations/33333333-0000-0000-0000-000000000002', ['code' => 'М99'], $this->h())->assertStatus(404);

        $this->putJson("/api/v1/clients/{$guid}", ['phone' => '1'], $this->h())->assertStatus(400)->assertJsonStructure(['errors' => ['full_name']]);
    }

    public function test_queries_contract_set_search_garage_stock_and_debt(): void
    {
        $contract = $this->accept($this->store());
        $this->getJson('/api/v1/contracts/000001', $this->h())->assertOk()
            ->assertJsonPath('sets.0.address', 'М01')->assertJsonPath('vehicle.plate', 'А123ВС86');
        $this->getJson('/api/v1/contracts/999999', $this->h())->assertStatus(404);
        $this->getJson('/api/v1/sets/000001', $this->h())->assertOk()->assertJsonPath('movements.0.action', 'accept');
        $this->getJson('/api/v1/clients/search?phone=9123456789', $this->h())->assertOk()->assertJsonPath('clients.0.contracts.0.number', '000001');
        $this->getJson('/api/v1/clients/search?plate=a123', $this->h())->assertOk()->assertJsonCount(1, 'clients');
        $this->getJson('/api/v1/clients/'.$contract->client_id.'/garage', $this->h())->assertOk()
            ->assertJsonPath('vehicles.0.garage_sets.0.qr_code', '000001');
        $this->getJson('/api/v1/stock?location=М01', $this->h())->assertOk()->assertJsonPath('total', 1);

        $this->putJson('/api/v1/contracts/000001/debt', ['debt' => 350.5], $this->h())->assertOk();
        $this->assertEquals(350.5, (float) $contract->fresh()->debt);
        $this->assertNotNull($contract->fresh()->debt_updated_at);
    }
}
