<?php

namespace App\Domain\Storage;

enum ContractStatus: string
{
    case Open = 'open';
    case Overdue = 'overdue';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Открыт',
            self::Overdue => 'Просрочен',
            self::Closed => 'Закрыт',
        };
    }
}
