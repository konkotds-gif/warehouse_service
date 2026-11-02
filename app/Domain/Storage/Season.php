<?php

namespace App\Domain\Storage;

enum Season: string
{
    case Winter = 'winter';
    case WinterStudded = 'winter_studded';
    case Summer = 'summer';
    case AllSeason = 'all_season';

    public function label(): string
    {
        return match ($this) {
            self::Winter => 'Зима',
            self::WinterStudded => 'Зима, шипы',
            self::Summer => 'Лето',
            self::AllSeason => 'Всесезон',
        };
    }
}
