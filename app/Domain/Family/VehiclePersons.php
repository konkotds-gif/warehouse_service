<?php

namespace App\Domain\Family;

use App\Domain\Support\DomainError;
use App\Domain\Support\Normalizer;
use App\Models\Client;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePerson;
use Illuminate\Support\Facades\DB;

/**
 * Семейный аккаунт, вариант Г: машина — главное звено. Владелец (vehicles.client_id) доверяет машину
 * другим людям; доверенное лицо видит хранения машины, сдаёт колёса на свой договор и забирает их.
 */
class VehiclePersons
{
    /** Клиент по точному телефону: при дублях — тот, у кого больше договоров. */
    public static function clientByPhone(?string $phone): ?Client
    {
        $phone = Normalizer::phone($phone);
        if (! $phone || strlen($phone) !== 12) {
            return null;
        }

        return Client::where('phone', $phone)->withCount('contracts')
            ->orderByDesc('contracts_count')->orderBy('created_at')->first();
    }

    /**
     * Добавить доверенное лицо: существующего клиента ($person) или нового по телефону и имени.
     *
     * @param  array{phone?: string|null, full_name?: string|null}  $new
     */
    public function add(Vehicle $vehicle, ?Client $person, array $new = [], string $source = 'staff', ?User $by = null, ?string $note = null): VehiclePerson
    {
        return DB::transaction(function () use ($vehicle, $person, $new, $source, $by, $note) {
            if (! $person) {
                $phone = Normalizer::phone($new['phone'] ?? null);
                if (! $phone || strlen($phone) !== 12) {
                    throw DomainError::invalid('Укажите телефон доверенного лица: +7 и 10 цифр', 'phone');
                }
                $person = self::clientByPhone($phone);
                if (! $person) {
                    $name = trim((string) ($new['full_name'] ?? ''));
                    if ($name === '') {
                        throw DomainError::invalid('Клиента с таким телефоном нет — укажите имя доверенного лица', 'full_name');
                    }
                    $person = Client::create(['full_name' => $name, 'phone' => $phone,
                        'notes' => 'Добавлен как доверенное лицо '.$vehicle->title()]);
                }
            }
            if ($person->id === $vehicle->client_id) {
                throw DomainError::invalid('Это владелец машины — он и так имеет к ней доступ', 'phone');
            }
            $existing = VehiclePerson::active()->where('vehicle_id', $vehicle->id)->where('client_id', $person->id)->first();
            if ($existing) {
                return $existing;
            }

            return VehiclePerson::create([
                'vehicle_id' => $vehicle->id, 'client_id' => $person->id, 'source' => $source,
                'added_by' => $by?->id, 'note' => $note,
            ]);
        });
    }

    public function revoke(VehiclePerson $person, string $reason): void
    {
        if ($person->revoked_at === null) {
            $person->revoke($reason);
        }
    }

    /** Смена владельца: прежние доверенности теряют силу (новый владелец решает сам). */
    public function revokeAll(Vehicle $vehicle, string $reason = 'owner_changed'): int
    {
        return VehiclePerson::active()->where('vehicle_id', $vehicle->id)
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    /** Объединение клиентов: доверенности дубля переходят к основной карточке. */
    public function mergeClients(Client $into, Client $from): void
    {
        foreach (VehiclePerson::active()->where('client_id', $from->id)->get() as $p) {
            $vehicle = Vehicle::find($p->vehicle_id);
            $dup = VehiclePerson::active()->where('vehicle_id', $p->vehicle_id)->where('client_id', $into->id)->exists();
            if ($dup || ($vehicle && $vehicle->client_id === $into->id)) {
                $p->revoke('merged');
            } else {
                $p->update(['client_id' => $into->id]);
            }
        }
        // Своя машина основной карточки: доверенность на неё самого себя не нужна
        VehiclePerson::active()->where('client_id', $into->id)
            ->whereIn('vehicle_id', Vehicle::where('client_id', $into->id)->select('id'))
            ->update(['revoked_at' => now(), 'revoked_reason' => 'merged']);
    }

    /** @return array<int, array<string, mixed>> */
    public static function present(Vehicle $vehicle): array
    {
        return $vehicle->persons()->with(['client', 'addedBy'])->get()->map(fn (VehiclePerson $p) => [
            'id' => $p->id,
            'client' => $p->client ? ['id' => $p->client->id, 'full_name' => $p->client->full_name, 'phone' => $p->client->phone] : null,
            'source' => $p->source,
            'source_label' => $p->source === 'booking' ? 'владелец в онлайн-записи' : ($p->addedBy?->name ?? 'сотрудник'),
            'note' => $p->note,
            'created_at' => $p->created_at?->toIso8601String(),
        ])->all();
    }
}
