<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Storage\ScanResolver;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\Normalizer;
use App\Models\Client;
use App\Models\Location;
use App\Models\StorageContract;
use App\Models\Vehicle;
use App\Models\WheelSet;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Запросы из 1С (чтение).
 */
class QueryController extends ApiController
{
    /** Найти клиента и его хранения по телефону и/или госномеру. */
    public function searchClients(Request $request): Response
    {
        $request->validate(['phone' => ['nullable', 'string'], 'plate' => ['nullable', 'string'], 'onec_guid' => ['nullable', 'uuid']]);
        $q = Client::query();
        $any = false;
        if ($phone = $request->query('phone')) {
            $digits = Normalizer::phoneDigits($phone);
            if (strlen($digits) >= 4) {
                $q->where('phone', 'like', '%'.$digits);
                $any = true;
            }
        }
        if ($plate = Normalizer::plate($request->query('plate'))) {
            $q->whereIn('id', Vehicle::where('plate', 'like', $plate.'%')->select('client_id'));
            $any = true;
        }
        if ($guid = $request->query('onec_guid')) {
            $q->where('onec_guid', $guid);
            $any = true;
        }
        if (! $any) {
            return $this->respond(['message' => 'Укажите phone, plate или onec_guid'], 400, 'error');
        }
        $clients = $q->limit(20)->get()->map(fn (Client $c) => $this->clientData($c, true))->all();

        return $this->respond(['clients' => $clients], 200, 'response');
    }

    /** Гараж клиента: машины, закреплённые комплекты, их состояние и где лежат. Принимает id платформы или GUID 1С. */
    public function garage(string $id): Response
    {
        $client = Client::where('id', $id)->orWhere('onec_guid', $id)->firstOrFail();

        return $this->respond($this->garageData($client), 200, 'garage');
    }

    /** Договор: комплекты, статус, адрес, оплачено до. */
    public function contract(string $number): Response
    {
        $contract = StorageContract::where('number', $number)->firstOrFail();

        return $this->respond($this->contractData($contract), 200, 'contract');
    }

    /** Комплект по QR бирки: статус, текущий адрес, история. */
    public function set(string $qr): Response
    {
        $sets = app(ScanResolver::class)->sets($qr);
        abort_if($sets->isEmpty(), 404, 'Комплект не найден');
        $set = $sets->first();
        $set->load(['contract', 'movements.user', 'cell', 'location', 'transfer']);

        return $this->respond([
            'contract_number' => $set->contract->number,
            'qr_code' => $set->qr_code,
            'qty' => $set->qty,
            'tire_size' => $set->tire_size,
            'with_rims' => $set->with_rims,
            'condition' => $set->condition->value,
            'status' => $set->status->value,
            'address' => $set->currentAddress(),
            'movements' => $set->movements->map(fn ($m) => $this->movementData($m))->all(),
        ], 200, 'set');
    }

    /** Остатки на хранении (для отчётов в 1С). location — код локации, status — статус комплекта. */
    public function stock(Request $request): Response
    {
        $request->validate(['location' => ['nullable', 'string'], 'status' => ['nullable', 'string'], 'limit' => ['nullable', 'integer', 'min:1', 'max:5000']]);
        $q = WheelSet::with(['contract.client', 'contract.vehicle', 'cell', 'location', 'transfer'])
            ->whereIn('status', SetStatus::activeValues())
            ->orderBy('qr_code');
        if ($code = $request->query('location')) {
            $loc = Location::where('code', $code)->firstOrFail();
            $q->where('current_location_id', $loc->id);
        }
        if ($status = $request->query('status')) {
            $q->where('status', SetStatus::from($status)->value);
        }
        $items = $q->limit((int) $request->query('limit', 1000))->get()->map(fn (WheelSet $s) => [
            'qr_code' => $s->qr_code,
            'contract_number' => $s->contract->number,
            'client_onec_guid' => $s->contract->client?->onec_guid,
            'plate' => $s->contract->vehicle?->plate,
            'status' => $s->status->value,
            'address' => $s->currentAddress(),
            'qty' => $s->qty,
            'tire_size' => $s->tire_size,
            'paid_until' => $s->contract->paid_until?->toDateString(),
        ])->all();

        return $this->respond(['total' => count($items), 'items' => $items], 200, 'stock');
    }
}
