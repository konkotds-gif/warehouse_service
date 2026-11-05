<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferLine extends Model
{
    protected $fillable = ['transfer_id', 'wheel_set_id', 'scanned_out', 'scanned_in', 'qty_out', 'qty_in', 'wheels_out', 'wheels_in'];

    protected function casts(): array
    {
        return ['scanned_out' => 'boolean', 'scanned_in' => 'boolean'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function wheelSet(): BelongsTo
    {
        return $this->belongsTo(WheelSet::class);
    }
}
