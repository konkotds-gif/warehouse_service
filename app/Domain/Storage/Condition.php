<?php

namespace App\Domain\Storage;

enum Condition: string
{
    case Used = 'used';
    case New = 'new';

    public function label(): string
    {
        return match ($this) {
            self::Used => 'БУ',
            self::New => 'НОВЫЕ',
        };
    }
}
