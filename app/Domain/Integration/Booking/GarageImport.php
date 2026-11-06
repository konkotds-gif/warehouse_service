<?php

namespace App\Domain\Integration\Booking;

use App\Domain\Family\VehiclePersons;
use App\Domain\Storage\Condition;
use App\Domain\Storage\GarageSetState;
use App\Domain\Storage\Season;
use App\Domain\Support\Normalizer;
use App\Models\Client;
use App\Models\GarageSet;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Гараж из онлайн-записи в хранение. Главный источник гаража и колёс — хранение (решение 2026-11-06),
 * поэтому из записи только ДОПОЛНЯЕМ: новые машины и комплекты сезонов, которых в хранении нет,
 * и пустые поля машины. Уже заполненное в хранении не перезаписывается. Чужие машины не трогаются.
 */
class GarageImport
{
    /**
     * @param  array{phone: string, full_name?: ?string, vehicles?: list<array<string, mixed>>}  $data
     * @return array{client_id: string, created_client: bool, vehicles: list<array<string, mixed>>}
     */
    public function import(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $client = VehiclePersons::clientByPhone($data['phone']);
            $created = false;
            if (! $client) {
                $client = Client::create([
                    'full_name' => trim((string) ($data['full_name'] ?? '')) ?: 'Клиент онлайн-записи',
                    'phone' => $data['phone'],
                    'notes' => 'Создан из онлайн-записи',
                ]);
                $created = true;
            }
            $result = [];
            foreach ($data['vehicles'] ?? [] as $v) {
                $result[] = $this->vehicle($client, $v);
            }

            return ['client_id' => $client->id, 'created_client' => $created, 'vehicles' => $result];
        });
    }

    /** @param array<string, mixed> $v */
    private function vehicle(Client $client, array $v): array
    {
        $plate = Normalizer::plate($v['plate'] ?? null);
        $ref = $v['ref'] ?? null;
        if (! $plate) {
            return ['ref' => $ref, 'status' => 'skipped', 'reason' => 'Без госномера машину не сопоставить'];
        }
        $vehicle = Vehicle::where('plate', $plate)->first();
        if ($vehicle && ! $client->canUseVehicle($vehicle)) {
            return ['ref' => $ref, 'plate' => $plate, 'status' => 'foreign', 'reason' => 'Машина с этим госномером в хранении оформлена на другого человека'];
        }
        $status = 'matched';
        if (! $vehicle) {
            $vehicle = $client->vehicles()->create([
                'plate' => $plate, 'make' => trim((string) ($v['make'] ?? '')) ?: null,
                'model' => trim((string) ($v['model'] ?? '')) ?: null,
                'year' => $v['year'] ?? null,
                'notes' => 'Добавлен из онлайн-записи',
            ]);
            $status = 'created';
        } else {
            $fill = [];
            foreach (['make', 'model', 'year'] as $f) {
                if (blank($vehicle->{$f}) && filled($v[$f] ?? null)) {
                    $fill[$f] = $v[$f];
                }
            }
            if ($fill) {
                $vehicle->update($fill);
            }
        }

        $sets = [];
        foreach ($v['wheels'] ?? [] as $w) {
            $sets[] = $this->wheels($vehicle, $w);
        }

        return ['ref' => $ref, 'plate' => $plate, 'status' => $status, 'vehicle_id' => $vehicle->id, 'garage_sets' => $sets];
    }

    /** @param array<string, mixed> $w */
    private function wheels(Vehicle $vehicle, array $w): array
    {
        $season = self::season($w['season'] ?? null, (bool) ($w['studded'] ?? false));
        $size = Normalizer::tireSize($w['tire_size'] ?? null);
        if (! $size) {
            return ['ref' => $w['ref'] ?? null, 'status' => 'skipped', 'reason' => 'Не указан размер шин'];
        }
        // Сезон уже есть в хранении — данные хранения главнее
        $same = GarageSet::where('vehicle_id', $vehicle->id)->where('state', '!=', GarageSetState::Retired->value)
            ->get()->first(fn (GarageSet $g) => self::sameSeason($g->season, $season));
        if ($same) {
            return ['ref' => $w['ref'] ?? null, 'status' => 'exists', 'garage_set_id' => $same->id];
        }
        $brand = trim(implode(' ', array_filter([$w['brand'] ?? null, $w['model'] ?? null])));
        $g = GarageSet::create([
            'vehicle_id' => $vehicle->id,
            'title' => $season ? $season->label() : 'Колёса',
            'season' => $season,
            'qty' => 4,
            'tire_size' => $size,
            'with_rims' => filled($w['rim_type'] ?? null),
            'condition' => Condition::Used,
            'brand_model' => $brand ?: null,
            'state' => GarageSetState::OnVehicle,
            'notes' => 'Из онлайн-записи (данные клиента, проверить при приёме)',
        ]);

        return ['ref' => $w['ref'] ?? null, 'status' => 'created', 'garage_set_id' => $g->id];
    }

    public static function season(?string $season, bool $studded = false): ?Season
    {
        return match ($season) {
            'winter' => $studded ? Season::WinterStudded : Season::Winter,
            'winter_studded' => Season::WinterStudded,
            'summer' => Season::Summer,
            'all_season' => Season::AllSeason,
            default => null,
        };
    }

    private static function sameSeason(?Season $a, ?Season $b): bool
    {
        $norm = fn (?Season $s) => $s === Season::WinterStudded ? Season::Winter : $s;

        return $norm($a) === $norm($b);
    }
}
