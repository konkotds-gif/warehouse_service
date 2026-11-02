<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageRow extends Model
{
    protected $fillable = ['location_id', 'row_no', 'title'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function cells(): HasMany
    {
        return $this->hasMany(StorageCell::class, 'row_id')->orderBy('cell_no');
    }
}
