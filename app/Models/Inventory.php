<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inventory extends Model
{
    protected $fillable = ['location_id', 'row_id', 'status', 'started_by', 'finished_at', 'result'];

    protected function casts(): array
    {
        return ['result' => 'array', 'finished_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(StorageRow::class, 'row_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(InventoryScan::class);
    }
}
