<?php

namespace App\Domain\Integration\Booking;

use App\Domain\Family\VehiclePersons;
use App\Domain\Storage\ContractStatus;
use App\Domain\Storage\GarageSetState;
use App\Domain\Storage\IssueService;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\Normalizer;
use App\Models\BookingOrder;
use App\Models\Client;
use App\Models\GarageSet;
use App\Models\Location;
use App\Models\StorageContract;
use App\Models\Vehicle;
use App\Models\VehiclePerson;
use App\Models\WheelSet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Что видит онлайн-запись по клиенту: его машины (свои и доверенные), комплекты гаража и колёса на хранении.
 * Телефон в запросе клиент подтвердил кодом из SMS на сайте записи — сравнение только точное.
 * Наружу не отдаются адреса ячеек, чужие телефоны и данные чужих машин.
 */
class BookingStorage
{
    public function __construct(private readonly IssueService $issue) {}

    /** @return Collection<int, Client> все карточки клиента с этим телефоном (дубли объединяются) */
    public function clients(string $phone): Collection
    {
        return Client::where('phone', $phone)->orderBy('created_at')->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function lookup(string $phone, ?string $plate = null, ?Location $store = null): array
    {
        $clients = $this->clients($phone);
        $ids = $clients->pluck('id')->all();
        $vehicles = $ids === [] ? collect() : Vehicle::with(['client', 'garageSets'])
            ->where(fn ($q) => $q->whereIn('client_id', $ids)
                ->orWhereIn('id', VehiclePerson::vehicleIdsFor($ids)))
            ->orderBy('created_at')->get();

        $foreign = null;
        if ($plate) {
            $mine = $vehicles->firstWhere('plate', $plate);
            if (! $mine) {
                $other = Vehicle::where('plate', $plate)->first();
                $foreign = ['plate' => $plate, 'known' => $other !== null, 'has_storage' => $other?->hasSetsInStorage() ?? false];
            }
        }

        $main = $clients->sortByDesc(fn (Client $c) => $c->contracts()->count())->first();

        return [
            'phone' => $phone,
            'client' => $main ? ['id' => $main->id, 'full_name' => $main->full_name, 'consent_sms' => $main->consent_sms] : null,
            'vehicles' => $vehicles->map(fn (Vehicle $v) => $this->vehicle($v, $ids, $store))->values()->all(),
            'foreign_plate' => $foreign,
            'contracts' => $this->contracts($ids, $store),
        ];
    }

    /**
     * Договоры хранения клиента для раздела «Хранение колёс» в кабинете записи: действующие — все,
     * закрытые — за последний год. Договоры без машины здесь тоже есть (в «Гараже» их не видно).
     *
     * @param  list<string>  $clientIds
     * @return list<array<string, mixed>>
     */
    public function contracts(array $clientIds, ?Location $store = null): array
    {
        if ($clientIds === []) {
            return [];
        }
        // Машины, где клиент — доверенное лицо: их действующие договоры тоже видны (закрытые чужие — нет)
        $trusted = VehiclePerson::vehicleIdsFor($clientIds)->pluck('id');
        $contracts = StorageContract::with(['vehicle', 'client', 'store', 'extensions', 'sets.contract', 'sets.location', 'sets.garageSet'])
            ->where(fn ($q) => $q
                ->where(fn ($own) => $own->whereIn('client_id', $clientIds)
                    ->where(fn ($w) => $w->where('status', '!=', ContractStatus::Closed->value)->orWhere('closed_at', '>=', now()->subYear())))
                ->orWhere(fn ($tr) => $tr->whereIn('vehicle_id', $trusted)->whereNotIn('client_id', $clientIds)
                    ->where('status', '!=', ContractStatus::Closed->value)))
            ->orderByRaw('status = ? asc', [ContractStatus::Closed->value])->orderByDesc('accepted_at')->get();
        $setIds = $contracts->flatMap(fn (StorageContract $c) => $c->sets->pluck('id'));
        $orders = BookingOrder::activeBySet($setIds);

        return $contracts->map(function (StorageContract $c) use ($orders, $store, $clientIds) {
            $own = in_array($c->client_id, $clientIds, true);
            $closed = $c->status === ContractStatus::Closed;
            $check = $closed ? ['debt' => 0, 'warnings' => [], 'overdue' => []] : $this->issue->debtCheck($c);
            $active = $c->sets->filter(fn (WheelSet $s) => $s->status->isActive());

            return [
                'number' => $c->number,
                // own — договор клиента; trusted — договор владельца машины, где клиент доверенное лицо
                'role' => $own ? 'own' : 'trusted',
                'owner_name' => $own ? null : self::shortName($c->client?->full_name),
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'store' => $c->store?->name,
                'accepted_at' => $c->accepted_at?->toIso8601String(),
                'storage_until' => $c->storage_until?->toDateString(),
                'paid_until' => $c->paid_until?->toDateString(),
                'closed_at' => $c->closed_at?->toIso8601String(),
                'amount' => (float) $c->amount,
                'debt' => round((float) $check['debt'] + (float) ($check['overdue']['due'] ?? 0), 2),
                'warnings' => array_values(array_filter($check['warnings'], fn ($w) => ! str_contains($w, '1С'))),
                'vehicle' => $c->vehicle ? ['id' => $c->vehicle->id, 'plate' => $c->vehicle->plate, 'make' => $c->vehicle->make, 'model' => $c->vehicle->model] : null,
                'wheels' => (int) $c->sets->sum('qty'),
                'sets' => $closed ? [] : $active->map(fn (WheelSet $s) => $this->set($s, $orders->get($s->id), $store))->values()->all(),
                'extensions' => $c->extensions->sortBy('seq')->map(fn ($e) => [
                    'number' => $e->number, 'months' => $e->months, 'storage_until' => $e->storage_until?->toDateString(),
                    'extended_at' => $e->extended_at?->toIso8601String(),
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /** @param list<string> $clientIds */
    public function vehicle(Vehicle $v, array $clientIds, ?Location $store = null): array
    {
        $owner = in_array($v->client_id, $clientIds, true);
        $contracts = StorageContract::where('vehicle_id', $v->id)->pluck('id');
        $sets = WheelSet::with(['contract', 'location', 'garageSet'])
            ->whereIn('contract_id', $contracts)->whereIn('status', SetStatus::activeValues())
            ->orderBy('qr_code')->get();
        $orders = BookingOrder::activeBySet($sets->pluck('id'));

        return [
            'id' => $v->id,
            'plate' => $v->plate,
            'make' => $v->make,
            'model' => $v->model,
            'year' => $v->year,
            'vin' => $owner ? $v->vin : null,
            'role' => $owner ? 'owner' : 'trusted',
            // Доверенному показываем только имя владельца — без телефона
            'owner_name' => $owner ? null : self::shortName($v->client?->full_name),
            'persons' => $owner ? collect(VehiclePersons::present($v))->map(fn ($p) => [
                'id' => $p['id'], 'full_name' => $p['client']['full_name'] ?? null, 'phone' => $p['client']['phone'] ?? null,
                'source' => $p['source'], 'all_vehicles' => $p['all_vehicles'], 'created_at' => $p['created_at'],
            ])->all() : [],
            // Доверенному — его собственная запись, чтобы он мог отказаться от доступа
            'my_person_id' => $owner ? null : $v->persons()->whereIn('client_id', $clientIds)->value('id'),
            'garage_sets' => $v->garageSets->filter(fn (GarageSet $g) => $g->state !== GarageSetState::Retired)
                ->map(fn (GarageSet $g) => [
                    'id' => $g->id,
                    'title' => $g->title,
                    'season' => $g->season?->value,
                    'qty' => $g->qty,
                    'tire_size' => $g->tire_size,
                    'with_rims' => $g->with_rims,
                    'condition' => $g->condition->value,
                    'brand_model' => $g->brand_model,
                    'in_storage' => $sets->contains(fn (WheelSet $s) => $s->garage_set_id === $g->id),
                    'updated_at' => $g->updated_at?->toIso8601String(),
                ])->values()->all(),
            'stored_sets' => $sets->map(fn (WheelSet $s) => $this->set($s, $orders->get($s->id), $store))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function set(WheelSet $s, ?BookingOrder $order, ?Location $store): array
    {
        $contract = $s->contract;
        $check = $this->issue->debtCheck($contract);
        $orderable = in_array($s->status, [SetStatus::Placed, SetStatus::Received, SetStatus::Accepted], true) && ! $order;

        return [
            'set_id' => $s->id,
            'qr_code' => $s->qr_code,
            'garage_set_id' => $s->garage_set_id,
            'contract_number' => $contract->number,
            'contract_status' => $contract->status->value,
            'season' => $s->season?->value,
            'title' => $s->garageSet?->title,
            'tire_size' => $s->tire_size,
            'qty' => $s->qty,
            'with_rims' => $s->with_rims,
            'brand_model' => $s->brand_model,
            'state' => self::state($s->status),
            'state_label' => self::stateLabel($s->status),
            'orderable' => $orderable,
            'booking_code' => $order?->booking_code,
            'ready_from' => $this->readyFrom($s, $store)->toIso8601String(),
            'storage_until' => $contract->storage_until?->toDateString(),
            'paid_until' => $contract->paid_until?->toDateString(),
            'debt' => round((float) $check['debt'] + (float) ($check['overdue']['due'] ?? 0), 2),
            'warnings' => array_values(array_filter($check['warnings'], fn ($w) => ! str_contains($w, '1С'))),
        ];
    }

    /**
     * С какого момента колёса могут быть в магазине записи: уже там или в том же здании — сразу,
     * иначе — через delivery_days дней к delivery_time (первая машина с хранением приезжает в 12:00).
     */
    public function readyFrom(WheelSet $s, ?Location $store): CarbonImmutable
    {
        $tz = config('app.display_timezone');
        $now = CarbonImmutable::now($tz);
        if ($store && ($s->current_location_id === $store->id || $s->location?->sameBuildingAs($store))) {
            return $now;
        }
        if ($store && $s->issue_store_id === $store->id && $s->status === SetStatus::Ready) {
            return $now;
        }
        [$h, $m] = array_map('intval', explode(':', (string) config('booking.delivery_time', '12:00')) + [1 => 0]);

        return $now->addDays(max(0, (int) config('booking.delivery_days', 1)))->setTime($h, $m);
    }

    public static function state(SetStatus $s): string
    {
        return match ($s) {
            SetStatus::Accepted, SetStatus::InTransit, SetStatus::Received, SetStatus::Placed => 'in_storage',
            SetStatus::Ordered => 'ordered',
            SetStatus::Ready => 'ready_in_store',
            default => 'other',
        };
    }

    public static function stateLabel(SetStatus $s): string
    {
        return match ($s) {
            SetStatus::Accepted, SetStatus::InTransit, SetStatus::Received, SetStatus::Placed => 'На хранении',
            SetStatus::Ordered => 'Заказаны к выдаче',
            SetStatus::Ready => 'Готовы к выдаче в магазине',
            SetStatus::Lost => 'Уточните в магазине',
            default => $s->label(),
        };
    }

    /** «Иванов Иван Иванович» → «Иван И.» (для доверенного лица — без лишних персональных данных). */
    public static function shortName(?string $full): ?string
    {
        $parts = preg_split('/\s+/u', trim((string) $full)) ?: [];
        if (count($parts) >= 2) {
            return $parts[1].' '.mb_substr($parts[0], 0, 1).'.';
        }

        return $parts[0] ?? null;
    }

    public static function phone(?string $raw): ?string
    {
        $p = Normalizer::phone($raw);

        return $p && strlen($p) === 12 && str_starts_with($p, '+7') ? $p : null;
    }
}
