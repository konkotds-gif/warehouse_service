<?php

namespace App\Domain\Storage;

use App\Domain\Integration\OneC\Outbox;
use App\Domain\Support\DomainError;
use App\Domain\Support\Normalizer;
use App\Domain\Support\NumberGenerator;
use App\Models\Client;
use App\Models\GarageSet;
use App\Models\Location;
use App\Models\StorageContract;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WheelSet;
use Illuminate\Support\Facades\DB;

/**
 * Приём на хранение (план, 6.1 и 6.1.1): клиент → авто → договор → комплекты → буфер магазина.
 */
final class AcceptanceService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly SetMover $mover,
        private readonly Outbox $outbox,
        private readonly Pricing $pricing,
    ) {}

    /**
     * @param  array{
     *   client_id?: string|null, client?: array{full_name: string, phone?: string|null, email?: string|null, consent_sms?: bool, consent_pd?: bool}|null,
     *   vehicle_id?: string|null, vehicle?: array{plate?: string|null, no_plate?: bool, make: string, model: string}|null,
     *   store_id: int, months?: int|null, notes?: string|null,
     *   sets: list<array{garage_set_id?: string|null, title?: string|null, qty: int, tire_size: string, with_rims: bool,
     *          condition: string, season?: string|null, brand_model?: string|null, damages?: list<string>, notes?: string|null}>
     * }  $data
     */
    public function accept(array $data, ?User $user = null): StorageContract
    {
        if (empty($data['sets'])) {
            throw DomainError::invalid('Добавьте хотя бы один комплект', 'sets');
        }

        return DB::transaction(function () use ($data, $user) {
            $store = Location::findOrFail($data['store_id']);
            if (! $store->isStore() || ! $store->is_active) {
                throw DomainError::invalid('Приём возможен только в действующем магазине', 'store_id');
            }

            $client = $this->resolveClient($data);
            $vehicle = $this->resolveVehicle($client, $data);
            $months = $this->pricing->validateMonths((int) ($data['months'] ?? min(6, Pricing::settings()['max_months'])));
            // Цена по диаметру каждого комплекта; проверяем до создания договора
            $lines = array_map(function ($d) use ($months) {
                $attrs = $this->validateSet($d);

                return $this->pricing->line($attrs['tire_size'], $attrs['qty'], $months);
            }, array_values($data['sets']));

            $contract = StorageContract::create([
                'number' => $this->numbers->contractNumber(),
                'client_id' => $client->id,
                'vehicle_id' => $vehicle->id,
                'store_id' => $store->id,
                'tariff_id' => $lines[0]['tariff']->id,
                'accepted_by' => $user?->id,
                'accepted_at' => now(),
                'storage_until' => now()->addMonthsNoOverflow($months)->toDateString(),
                'months' => $months,
                'amount' => array_sum(array_column($lines, 'amount')),
                'status' => ContractStatus::Open,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach (array_values($data['sets']) as $i => $setData) {
                $this->acceptSet($contract, $vehicle, $store, $setData, $i, $lines[$i]);
            }

            $contract->load('sets');
            $this->outbox->record('contract.created', $this->outbox->contractPayload($contract));

            return $contract;
        });
    }

    private function acceptSet(StorageContract $contract, Vehicle $vehicle, Location $store, array $d, int $index, array $line): WheelSet
    {
        $attrs = $this->validateSet($d);

        if (! empty($d['garage_set_id'])) {
            $garage = GarageSet::whereKey($d['garage_set_id'])->lockForUpdate()->firstOrFail();
            if ($garage->vehicle_id !== $vehicle->id) {
                throw DomainError::invalid('Комплект из гаража закреплён за другим автомобилем', 'sets');
            }
            $active = WheelSet::where('garage_set_id', $garage->id)->whereIn('status', SetStatus::activeValues())->exists();
            if ($active) {
                throw new DomainError("Комплект «{$garage->title}» уже находится на хранении");
            }
            // Правка при приёме обновляет гараж (последнее известное состояние)
            $garage->fill($attrs + ['title' => $d['title'] ?? $garage->title]);
        } elseif ($existing = $this->matchingGarageSet($vehicle, $attrs)) {
            // Тот же комплект уже есть в гараже машины (не выбран продавцом) — не плодим дубли
            $garage = $existing;
            $garage->fill($attrs);
        } else {
            $garage = new GarageSet($attrs + [
                'vehicle_id' => $vehicle->id,
                'title' => $d['title'] ?? $this->defaultTitle($attrs),
            ]);
        }
        $garage->state = GarageSetState::InStorage;
        $garage->last_contract_id = $contract->id;
        $garage->save();

        $set = WheelSet::create($attrs + [
            'garage_set_id' => $garage->id,
            'contract_id' => $contract->id,
            'tariff_id' => $line['tariff']->id,
            'price' => $line['price'],
            'amount' => $line['amount'],
            'qr_code' => $index === 0 ? $contract->number : $contract->number.'/'.($index + 1),
            'status' => SetStatus::Accepted,
            'current_location_id' => $store->id,
        ]);

        $this->mover->log($set, 'accept', null, SetStatus::Accepted, null, ['qty' => $set->qty]);

        return $set;
    }

    private function matchingGarageSet(Vehicle $vehicle, array $attrs): ?GarageSet
    {
        return GarageSet::where('vehicle_id', $vehicle->id)
            ->where('tire_size', $attrs['tire_size'])
            ->where('with_rims', $attrs['with_rims'])
            ->where('state', '!=', GarageSetState::Retired->value)
            ->whereDoesntHave('wheelSets', fn ($q) => $q->whereIn('status', SetStatus::activeValues()))
            ->lockForUpdate()
            ->first();
    }

    /** @return array<string, mixed> */
    public function validateSet(array $d): array
    {
        $qty = (int) ($d['qty'] ?? 0);
        if ($qty < 1 || $qty > 8) {
            throw DomainError::invalid('Количество колёс — от 1 до 8', 'qty');
        }
        $size = Normalizer::tireSize($d['tire_size'] ?? null);
        if (! $size || ! Normalizer::isValidTireSize($size)) {
            throw DomainError::invalid('Укажите полный размер шин, например 205/55 R16 94H', 'tire_size');
        }
        $condition = Condition::tryFrom((string) ($d['condition'] ?? ''));
        if (! $condition) {
            throw DomainError::invalid('Укажите состояние: БУ или НОВЫЕ', 'condition');
        }
        if (! array_key_exists('with_rims', $d) || $d['with_rims'] === null || $d['with_rims'] === '') {
            throw DomainError::invalid('Укажите: с дисками или без дисков', 'with_rims');
        }
        $season = ! empty($d['season']) ? Season::tryFrom($d['season']) : null;

        return [
            'qty' => $qty,
            'tire_size' => $size,
            'with_rims' => filter_var($d['with_rims'], FILTER_VALIDATE_BOOLEAN),
            'condition' => $condition,
            'season' => $season,
            'brand_model' => $d['brand_model'] ?? null,
            // повреждения имеют смысл только для БУ; у дисков — только если комплект с дисками
            'damages' => $condition === Condition::Used
                ? array_values(array_filter(Damage::normalize($d['damages'] ?? []),
                    fn ($v) => filter_var($d['with_rims'], FILTER_VALIDATE_BOOLEAN) || ! Damage::from($v)->isRim()))
                : [],
            'notes' => $d['notes'] ?? null,
        ];
    }

    private function resolveClient(array $data): Client
    {
        if (! empty($data['client_id'])) {
            return Client::findOrFail($data['client_id']);
        }
        $c = $data['client'] ?? null;
        if (! $c || blank($c['full_name'] ?? null)) {
            throw DomainError::invalid('Укажите клиента', 'client');
        }
        $phone = Normalizer::phone($c['phone'] ?? null);
        if ($phone && ($existing = Client::where('phone', $phone)->first())) {
            return $existing; // защита от дублей: клиент с таким телефоном уже есть
        }
        $client = Client::create([
            'full_name' => trim($c['full_name']),
            'phone' => $phone,
            'email' => $c['email'] ?? null,
            'consent_sms' => (bool) ($c['consent_sms'] ?? false),
            'consent_pd' => (bool) ($c['consent_pd'] ?? true),
        ]);
        $this->outbox->record('client.created', self::clientPayload($client));

        return $client;
    }

    private function resolveVehicle(Client $client, array $data): Vehicle
    {
        if (! empty($data['vehicle_id'])) {
            $vehicle = Vehicle::findOrFail($data['vehicle_id']);
            if ($vehicle->client_id !== $client->id) {
                throw DomainError::invalid('Автомобиль принадлежит другому клиенту', 'vehicle_id');
            }

            return $vehicle;
        }
        $v = $data['vehicle'] ?? [];
        $noPlate = filter_var($v['no_plate'] ?? false, FILTER_VALIDATE_BOOLEAN); // новый автомобиль, ещё без номеров
        foreach (['plate' => 'госномер', 'make' => 'марку', 'model' => 'модель'] as $field => $label) {
            if (blank($v[$field] ?? null) && ! ($field === 'plate' && $noPlate)) {
                throw DomainError::invalid("Укажите {$label} автомобиля", 'vehicle.'.$field);
            }
        }
        $plate = $noPlate ? null : Normalizer::plate($v['plate']);
        $existing = $plate ? $client->vehicles()->where('plate', $plate)->first() : null;

        return $existing ?? $client->vehicles()->create([
            'plate' => $plate, 'make' => trim($v['make']), 'model' => trim($v['model']),
        ]);
    }

    private function defaultTitle(array $attrs): string
    {
        $season = $attrs['season'] instanceof Season ? $attrs['season']->label() : 'Комплект';

        return $season.' '.$attrs['tire_size'].($attrs['with_rims'] ? ' на дисках' : '');
    }

    public static function clientPayload(Client $client): array
    {
        return [
            'client_id' => $client->id,
            'client_onec_guid' => $client->onec_guid,
            'full_name' => $client->full_name,
            'phone' => $client->phone,
            'email' => $client->email,
        ];
    }
}
