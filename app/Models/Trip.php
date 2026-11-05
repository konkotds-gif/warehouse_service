<?php

namespace App\Models;

use App\Domain\Warehouse\TripStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ведомость заданий на перевозку: водитель + одно или несколько перемещений. */
class Trip extends Model
{
    use HasUuids;

    protected $fillable = ['number', 'status', 'driver', 'vehicle', 'created_by', 'shipped_at', 'completed_at', 'notes'];

    protected function casts(): array
    {
        return ['status' => TripStatus::class, 'shipped_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
