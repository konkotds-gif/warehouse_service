<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\OneC\Outbox;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\GarageSet;
use App\Models\Movement;
use App\Models\StorageContract;
use App\Models\Vehicle;
use App\Models\WheelSet;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

abstract class ApiController extends Controller
{
    protected function respond(mixed $data, int $status = 200, string $root = 'response'): Response
    {
        return ApiResponse::make($data, $status, request(), $root);
    }

    protected function clientData(Client $c, bool $withContracts = false): array
    {
        $data = [
            'id' => $c->id,
            'onec_guid' => $c->onec_guid,
            'full_name' => $c->full_name,
            'phone' => $c->phone,
            'email' => $c->email,
            'consent_sms' => $c->consent_sms,
        ];
        if ($withContracts) {
            $data['contracts'] = $c->contracts()->with(['sets.cell', 'sets.location', 'sets.transfer', 'vehicle', 'store'])->limit(50)->get()
                ->map(fn (StorageContract $k) => $this->contractData($k))->all();
        }

        return $data;
    }

    protected function contractData(StorageContract $k): array
    {
        $k->loadMissing(['sets.cell', 'sets.location', 'sets.transfer', 'vehicle', 'store', 'client', 'tariff', 'extensions']);
        $outbox = app(Outbox::class);

        return [
            'number' => $k->number,
            'id' => $k->id,
            'status' => $k->status->value,
            'client_id' => $k->client_id,
            'client_onec_guid' => $k->client?->onec_guid,
            'vehicle' => $k->vehicle ? ['plate' => $k->vehicle->plate, 'make' => $k->vehicle->make, 'model' => $k->vehicle->model] : null,
            'store_code' => $k->store?->code,
            'service_code' => $k->tariff?->code,
            'accepted_at' => $k->accepted_at?->toIso8601String(),
            'storage_until' => $k->storage_until?->toDateString(),
            'paid_until' => $k->paid_until?->toDateString(),
            'amount' => (float) $k->amount,
            'debt' => (float) $k->debt,
            'onec_doc_guid' => $k->onec_doc_guid,
            'sets' => $k->sets->map(fn (WheelSet $s) => $outbox->setPayload($s))->all(),
            'extensions' => $k->extensions->map(fn ($e) => $outbox->extensionPayload($e) + ['onec_doc_guid' => $e->onec_doc_guid])->values()->all(),
        ];
    }

    protected function garageData(Client $client): array
    {
        $vehicles = $client->vehicles()->with(['garageSets.wheelSets' => fn ($q) => $q->latest()])->get();

        return [
            'client' => $this->clientData($client),
            'vehicles' => $vehicles->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'plate' => $v->plate,
                'make' => $v->make,
                'model' => $v->model,
                'garage_sets' => $v->garageSets->map(function (GarageSet $g) {
                    $active = $g->wheelSets->first(fn ($s) => $s->status->isActive());

                    return [
                        'id' => $g->id,
                        'title' => $g->title,
                        'season' => $g->season?->value,
                        'qty' => $g->qty,
                        'tire_size' => $g->tire_size,
                        'with_rims' => $g->with_rims,
                        'condition' => $g->condition->value,
                        'state' => $g->state->value,
                        'qr_code' => $active?->qr_code,
                        'address' => $active?->currentAddress(),
                        'set_status' => $active?->status->value,
                    ];
                })->all(),
            ])->all(),
        ];
    }

    protected function movementData(Movement $m): array
    {
        return [
            'at' => $m->at->toIso8601String(),
            'action' => $m->action,
            'from_address' => $m->from_address,
            'to_address' => $m->to_address,
            'from_status' => $m->from_status,
            'to_status' => $m->to_status,
            'user' => $m->user?->name,
            'note' => $m->note,
        ];
    }

    protected function input(Request $request, string $key, mixed $default = null): mixed
    {
        $v = $request->input($key, $default);

        return $v === '' ? $default : $v;
    }
}
