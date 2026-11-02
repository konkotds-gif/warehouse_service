<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** Услуга хранения (номенклатура 1С). */
class Tariff extends Model
{
    use Audited;

    protected $fillable = ['onec_guid', 'code', 'name', 'price', 'period', 'months', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean', 'months' => 'integer'];
    }
}
