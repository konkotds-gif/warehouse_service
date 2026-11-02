<?php

namespace App\Domain\Storage;

enum GarageSetState: string
{
    case InStorage = 'in_storage';
    case OnVehicle = 'on_vehicle';
    case WithClient = 'with_client';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::InStorage => 'На хранении',
            self::OnVehicle => 'На машине',
            self::WithClient => 'У клиента',
            self::Retired => 'Продан / утилизирован',
        };
    }
}
