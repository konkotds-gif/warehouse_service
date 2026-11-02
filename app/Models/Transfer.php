<?php

namespace App\Models;

use App\Domain\Warehouse\TransferStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Документ перемещения комплектов между локациями. */
class Transfer extends Model
{
    use HasUuids;

    protected $fillable = ['number', 'from_location_id', 'to_location_id', 'status', 'purpose', 'driver',
        'created_by', 'shipped_at', 'received_at', 'notes'];

    protected function casts(): array
    {
        return ['status' => TransferStatus::class, 'shipped_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TransferLine::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
