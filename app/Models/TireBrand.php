<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Справочник брендов шин (селект с поиском в мастере приёма и в гараже клиента). */
class TireBrand extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return list<string> */
    public static function activeNames(): array
    {
        return static::where('is_active', true)->orderBy('name')->pluck('name')->all();
    }
}
