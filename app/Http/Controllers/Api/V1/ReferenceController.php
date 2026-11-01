<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Warehouse\LocationType;
use App\Models\Client;
use App\Models\Location;
use App\Models\Tariff;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Справочники 1С → платформа (план, 9.4).
 */
class ReferenceController extends ApiController
{
    /**
     * Создать/обновить клиента (контрагента 1С).
     *
     * Если клиент был создан в магазине (событие client.created), передайте client_id — платформа свяжет его с GUID.
     */
    public function client(Request $request, string $onecGuid): Response
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'max:255'],
            'consent_sms' => ['nullable', 'boolean'],
            'client_id' => ['nullable', 'uuid'],
        ]);
        $client = Client::withTrashed()->where('onec_guid', $onecGuid)->first()
            ?? (! empty($data['client_id']) ? Client::find($data['client_id']) : null);
        $created = ! $client;
        $client ??= new Client;
        $client->fill([
            'onec_guid' => $onecGuid,
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? $client->phone,
            'email' => $data['email'] ?? $client->email,
        ]);
        if (array_key_exists('consent_sms', $data) && $data['consent_sms'] !== null) {
            $client->consent_sms = (bool) $data['consent_sms'];
        }
        $client->save();

        return $this->respond($this->clientData($client), $created ? 201 : 200, 'client');
    }

    /** Услуга хранения: код, наименование, цена за сезон/месяц. */
    public function service(Request $request, string $onecGuid): Response
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'period' => ['nullable', 'in:season,month'],
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $tariff = Tariff::where('onec_guid', $onecGuid)->first() ?? Tariff::where('code', $data['code'])->first();
        $created = ! $tariff;
        $tariff ??= new Tariff;
        $tariff->fill([
            'onec_guid' => $onecGuid,
            'code' => $data['code'],
            'name' => $data['name'],
            'price' => $data['price'],
            'period' => $data['period'] ?? $tariff->period ?? 'season',
            'months' => $data['months'] ?? $tariff->months ?? 6,
            'is_active' => $data['is_active'] ?? true,
        ])->save();

        return $this->respond([
            'id' => $tariff->id, 'onec_guid' => $tariff->onec_guid, 'code' => $tariff->code, 'name' => $tariff->name,
            'price' => (float) $tariff->price, 'period' => $tariff->period, 'months' => $tariff->months, 'is_active' => $tariff->is_active,
        ], $created ? 201 : 200, 'service');
    }

    /** Сопоставление подразделения/склада 1С с локацией платформы (по коду). */
    public function location(Request $request, string $onecGuid): Response
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:4'],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'in:store,warehouse'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
        $location = Location::where('onec_guid', $onecGuid)->first() ?? Location::where('code', $data['code'])->first();
        $created = false;
        if (! $location) {
            if (empty($data['name']) || empty($data['type'])) {
                return $this->respond(['message' => 'Локация с таким кодом не найдена; для создания передайте name и type'], 404, 'error');
            }
            $location = new Location(['code' => $data['code'], 'type' => LocationType::from($data['type'])]);
            $created = true;
        }
        $location->fill(array_filter([
            'onec_guid' => $onecGuid,
            'name' => $data['name'] ?? null,
            'address' => $data['address'] ?? null,
        ], fn ($v) => $v !== null))->save();

        return $this->respond([
            'id' => $location->id, 'code' => $location->code, 'name' => $location->name,
            'type' => $location->type->value, 'onec_guid' => $location->onec_guid,
        ], $created ? 201 : 200, 'location');
    }
}
