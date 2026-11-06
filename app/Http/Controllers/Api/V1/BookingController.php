<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Family\VehiclePersons;
use App\Domain\Integration\Booking\BookingOrders;
use App\Domain\Integration\Booking\BookingStorage;
use App\Domain\Integration\Booking\GarageImport;
use App\Domain\Support\DomainError;
use App\Domain\Support\Normalizer;
use App\Models\BookingOrder;
use App\Models\Location;
use App\Models\Vehicle;
use App\Models\VehiclePerson;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * API для онлайн-записи (zapis.diskimira.com), ключ с областью booking.
 * Телефон в запросах клиент подтвердил кодом из SMS на сайте записи; сайт записи — доверенная сторона.
 * Документация: docs/API.md, раздел «Онлайн-запись».
 */
class BookingController extends ApiController
{
    public function __construct(
        private readonly BookingStorage $storage,
        private readonly BookingOrders $orders,
    ) {}

    /** Машины клиента (свои и доверенные), комплекты гаража и колёса на хранении. */
    public function lookup(Request $request): Response
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'plate' => ['nullable', 'string', 'max:16'],
            'store_code' => ['nullable', 'string', 'max:16'],
        ]);
        $phone = $this->phone($data['phone']);
        $plate = Normalizer::plate($data['plate'] ?? null);
        $store = ! empty($data['store_code']) ? Location::where('code', $data['store_code'])->first() : null;
        $result = $this->storage->lookup($phone, $plate, $store);
        $this->log($request, 'lookup', $phone, $plate, count($result['vehicles']));

        return $this->respond($result, 200, 'lookup');
    }

    /** Гараж из записи: дополнить хранение новыми машинами и комплектами (хранение — главный источник). */
    public function importGarage(Request $request, GarageImport $import): Response
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'vehicles' => ['array', 'max:20'],
            'vehicles.*.ref' => ['nullable'],
            'vehicles.*.plate' => ['nullable', 'string', 'max:16'],
            'vehicles.*.make' => ['nullable', 'string', 'max:64'],
            'vehicles.*.model' => ['nullable', 'string', 'max:64'],
            'vehicles.*.year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'vehicles.*.wheels' => ['array', 'max:6'],
            'vehicles.*.wheels.*.ref' => ['nullable'],
            'vehicles.*.wheels.*.season' => ['nullable', 'in:summer,winter,winter_studded,all_season'],
            'vehicles.*.wheels.*.studded' => ['nullable', 'boolean'],
            'vehicles.*.wheels.*.tire_size' => ['nullable', 'string', 'max:40'],
            'vehicles.*.wheels.*.brand' => ['nullable', 'string', 'max:64'],
            'vehicles.*.wheels.*.model' => ['nullable', 'string', 'max:64'],
            'vehicles.*.wheels.*.rim_type' => ['nullable', 'string', 'max:16'],
        ]);
        $data['phone'] = $this->phone($data['phone']);
        $result = $import->import($data);
        $this->log($request, 'garage_import', $data['phone'], null, count($result['vehicles']));

        return $this->respond($result, 200, 'garage');
    }

    public function placeOrder(Request $request): Response
    {
        $data = $request->validate([
            'booking_code' => ['required', 'string', 'max:40'],
            'set_id' => ['required', 'uuid'],
            'store_code' => ['required', 'string', 'max:16'],
            'starts_at' => ['required', 'date'],
            'phone' => ['required', 'string', 'max:32'],
        ]);
        $order = $this->orders->place($data['booking_code'], $data['set_id'], $data['store_code'], $data['starts_at'], $this->phone($data['phone']));

        return $this->respond($this->orders->present($order), 201, 'order');
    }

    public function updateOrder(Request $request, string $code): Response
    {
        $data = $request->validate(['starts_at' => ['required', 'date'], 'store_code' => ['nullable', 'string', 'max:16']]);

        return $this->respond($this->orders->present($this->orders->update($code, $data['starts_at'], $data['store_code'] ?? null)), 200, 'order');
    }

    public function cancelOrder(string $code): Response
    {
        $order = $this->orders->cancel($code);

        return $order ? $this->respond($this->orders->present($order), 200, 'order')
            : $this->respond(['booking_code' => $code, 'status' => 'none'], 200, 'order');
    }

    public function showOrder(string $code): Response
    {
        $order = BookingOrder::with(['wheelSet.contract', 'store'])->where('booking_code', $code)->firstOrFail();

        return $this->respond($this->orders->present($order), 200, 'order');
    }

    /** Владелец (вошёл на сайте записи по SMS) добавляет доверенное лицо к своей машине. */
    public function addPerson(Request $request, Vehicle $vehicle, VehiclePersons $persons): Response
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'person_phone' => ['required', 'string', 'max:32'],
            'person_name' => ['nullable', 'string', 'max:255'],
            'all_vehicles' => ['nullable', 'boolean'],
        ]);
        $this->assertOwner($vehicle, $this->phone($data['phone']));
        $person = $persons->add($vehicle, null, ['phone' => $data['person_phone'], 'full_name' => $data['person_name'] ?? null],
            'booking', null, 'Добавил владелец в онлайн-записи', (bool) ($data['all_vehicles'] ?? false));

        return $this->respond(['id' => $person->id, 'full_name' => $person->client->full_name, 'phone' => $person->client->phone,
            'all_vehicles' => $person->all_vehicles], 201, 'person');
    }

    public function revokePerson(Request $request, Vehicle $vehicle, VehiclePerson $person, VehiclePersons $persons): Response
    {
        abort_unless(VehiclePerson::forVehicle($vehicle)->whereKey($person->id)->exists(), 404);
        $phone = $this->phone((string) $request->input('phone', $request->query('phone')));
        // Убрать может владелец машины или само доверенное лицо (отказаться от доступа)
        $self = $person->client && $person->client->phone === $phone;
        if (! $self) {
            $this->assertOwner($vehicle, $phone);
        }
        $persons->revoke($person, $self ? 'self' : 'owner');

        return $this->respond(['id' => $person->id, 'revoked' => true], 200, 'person');
    }

    private function assertOwner(Vehicle $vehicle, string $phone): void
    {
        if (! $this->storage->clients($phone)->contains('id', $vehicle->client_id)) {
            throw new DomainError('Менять доверенных лиц может только владелец машины', 403);
        }
    }

    private function phone(string $raw): string
    {
        return BookingStorage::phone($raw) ?? throw DomainError::invalid('Телефон: +7 и 10 цифр', 'phone');
    }

    private function log(Request $request, string $action, ?string $phone, ?string $plate, int $found): void
    {
        DB::table('api_access_log')->insert([
            'api_key_id' => $request->attributes->get('api_key')?->id,
            'action' => $action, 'phone' => $phone, 'plate' => $plate, 'ip' => $request->ip(),
            'found' => min($found, 65535), 'created_at' => now(),
        ]);
    }
}
