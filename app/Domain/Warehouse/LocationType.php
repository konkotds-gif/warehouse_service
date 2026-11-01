<?php

namespace App\Domain\Warehouse;

enum LocationType: string
{
    case Store = 'store';
    case Warehouse = 'warehouse';

    public function label(): string
    {
        return match ($this) {
            self::Store => 'Магазин',
            self::Warehouse => 'Склад',
        };
    }
}
