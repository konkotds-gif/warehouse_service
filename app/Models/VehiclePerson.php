<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Доверенное лицо при машине (семейный аккаунт, вариант Г). Владелец машины — vehicles.client_id,
 * здесь только те, кому владелец доверил машину: они видят её хранения, сдают и забирают колёса.
 * Отзыв не удаляет строку — остаётся история (revoked_at, revoked_reason).
 */
class VehiclePerson extends Model
{
    protected $table = 'vehicle_persons';

    protected $fillable = ['vehicle_id', 'client_id', 'source', 'added_by', 'note', 'revoked_at', 'revoked_reason'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    public const REVOKE_REASONS = [
        'owner' => 'отозвал владелец',
        'staff' => 'отозвал сотрудник',
        'owner_changed' => 'сменился владелец машины',
        'merged' => 'карточки клиентов объединены',
        'self' => 'отказался сам',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
