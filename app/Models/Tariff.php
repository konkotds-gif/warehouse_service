<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/**
 * Услуга хранения (номенклатура 1С). Строка прайса: цена за 1 колесо в месяц для диаметров
 * diameter_from…diameter_to (код 1С — code, например ЦБ000335 для R16).
 */
class Tariff extends Model
{
    use Audited;

    protected $fillable = ['onec_guid', 'code', 'name', 'diameter_from', 'diameter_to', 'price', 'period', 'months', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean', 'months' => 'integer',
            'diameter_from' => 'integer', 'diameter_to' => 'integer'];
    }
}
