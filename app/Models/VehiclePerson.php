<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Доверенное лицо при машине (семейный аккаунт, вариант Г). Владелец машины — vehicles.client_id,
 * здесь только те, кому владелец доверил машину: они видят её хранения, сдают и забирают колёса.
 * all_vehicles — доверие ко всем машинам владельца owner_id, включая появившиеся позже.
 * Отзыв не удаляет строку — остаётся история (revoked_at, revoked_reason).
 */
class VehiclePerson extends Model
{
    protected $table = 'vehicle_persons';

    protected $fillable = ['vehicle_id', 'client_id', 'owner_id', 'all_vehicles', 'source', 'added_by', 'note', 'revoked_at', 'revoked_reason'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'all_vehicles' => 'boolean'];
    }

    public const REVOKE_REASONS = [
        'owner' => 'отозвал владелец',
        'staff' => 'отозвал сотрудник',
        'owner_changed' => 'сменился владелец машины',
        'merged' => 'карточки клиентов объединены',
        'self' => 'отказался сам',
        'all_vehicles' => 'заменено доверием ко всем машинам',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at');
    }

    /** Доверенности, действующие для машины: при ней самой и «ко всем машинам» её владельца. */
    public function scopeForVehicle(Builder $q, Vehicle $vehicle): Builder
    {
        return $q->where(fn ($w) => $w
            ->where(fn ($one) => $one->where('vehicle_id', $vehicle->id)->where('all_vehicles', false))
            ->orWhere(fn ($all) => $all->where('all_vehicles', true)->where('owner_id', $vehicle->client_id)));
    }

    /**
     * id машин, где клиенты — доверенные лица (подзапрос для whereIn).
     *
     * @param  list<string>  $clientIds
     */
    public static function vehicleIdsFor(array $clientIds): Builder
    {
        return Vehicle::query()->select('vehicles.id')->where(fn ($q) => $q
            ->whereIn('vehicles.id', self::active()->whereIn('client_id', $clientIds)->where('all_vehicles', false)->select('vehicle_id'))
            ->orWhereIn('vehicles.client_id', self::active()->whereIn('client_id', $clientIds)->where('all_vehicles', true)->select('owner_id')))
            ->whereNotIn('vehicles.client_id', $clientIds);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'owner_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function revoke(string $reason): void
    {
        $this->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }
}
