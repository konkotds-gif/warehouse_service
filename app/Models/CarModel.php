<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Модель авто из справочника системы записи (id внешний, не меняется). */
class CarModel extends Model
{
    public $incrementing = false;

    protected $fillable = ['id', 'car_make_id', 'slug', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(CarMake::class, 'car_make_id');
    }
}
