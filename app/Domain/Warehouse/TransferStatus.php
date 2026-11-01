<?php

namespace App\Domain\Warehouse;

enum TransferStatus: string
{
    case Created = 'created';
    case Shipped = 'shipped';
    case Received = 'received';
    case Discrepancy = 'discrepancy';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Создано',
            self::Shipped => 'В пути',
            self::Received => 'Принято',
            self::Discrepancy => 'Расхождение',
            self::Cancelled => 'Отменено',
        };
    }
}
