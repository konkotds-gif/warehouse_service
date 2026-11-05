<?php

namespace App\Domain\Warehouse;

/** Статус ведомости заданий на перевозку. */
enum TripStatus: string
{
    case Loading = 'loading';
    case Shipped = 'shipped';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Loading => 'Погрузка',
            self::Shipped => 'В пути',
            self::Done => 'Доставлено',
            self::Cancelled => 'Отменена',
        };
    }
}
