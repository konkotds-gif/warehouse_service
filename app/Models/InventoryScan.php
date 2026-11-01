<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryScan extends Model
{
    public $timestamps = false;

    protected $fillable = ['inventory_id', 'cell_address', 'qr_code', 'user_id', 'scanned_at'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }
}
