<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Начальный справочник брендов шин (список с сайта «Диски Мира», 2026-11-05).
 * Повторный запуск безопасен: существующие бренды не дублируются.
 */
class TireBrandSeeder extends Seeder
{
    public const BRANDS = [
        'Accelera', 'Advenza', 'Altenzo', 'Annaite', 'Antares', 'Aoteli', 'Aplus', 'Arcron', 'Arduzza', 'Arivo',
        'Armstrong', 'Atlander', 'Attar', 'Austone', 'Autogreen', 'Avatyre', 'Barez Tires', 'Bars', 'Barum', 'Bearway',
        'BF Goodrich', 'Blackarrow', 'BlackLion', 'Boto', 'Brasa', 'Bridgestone', 'Cachland', 'Ceat', 'Centara', 'Chao Yang',
        'Comforser', 'Compasal', 'Continental', 'Contyre', 'Cooper', 'Cordiant', 'CST', 'Delinte', 'Delmax', 'Double Coin',
        'DoubleStar', 'Dunlop', 'Duraturn', 'Dynamo', 'Ecovision', 'Evergreen', 'Falken', 'Farroad', 'Federal', 'Firemax',
        'Foman', 'ForceLand', 'Fortune', 'Forward', 'Fronway', 'Fulda', 'General', 'Gislaved', 'Goform', 'GOLDEN CROWN',
        'GoldStone', 'Goodride', 'Goodyear', 'Greentrac', 'Grenlander', 'Gripmax', 'GT Radial', 'Habilead', 'Haida', 'Hankook',
        'Headway', 'Hifly', 'Hilo', 'Ikon (Nokian Tyres)', 'iLink', 'Imperial', 'Jinyu Tires', 'Joyroad', 'Kapsen', 'Kavir Tire',
        'Kinforest', 'Kingboss', 'Kingnate', 'Kormoran', 'Kumho', 'Kustone', 'Lakesea', 'Landsail', 'Landspider', 'Lanvigator',
        'Lassa', 'Laufenn', 'Leao', 'LingLong', 'Marshal', 'Massimo', 'Matador', 'Maxtrek', 'Maxxis', 'Mazzini',
        'Meteor', 'Michelin', 'Mileking', 'Mirage', 'Nankang', 'Neolin', 'Nereus', 'Nexen', 'Nitto', 'Nokian Tyres (Ikon Tyres)',
        'NorTec', 'NОRTEC', 'ONYX', 'Opals', 'Ornate', 'Otani', 'Ovation', 'Pace', 'PallyKing', 'Petlas',
        'Pirelli', 'Pirelli Formula', 'PowerTrac', 'Premiorri', 'Prinx', 'Profil', 'Rapid', 'Rauffan', 'Razi', 'Roadboss',
        'Roadcruza', 'Roadking', 'Roadmarch', 'Roador', 'Roadstone', 'RoadX', 'RockBlade', 'Rotalla', 'Royal Black', 'Rydanz',
        'Sailun', 'Satoya', 'Sonix', 'Starmaxx', 'Sumaxx', 'Sunfull', 'SUNWIDE', 'Superia', 'Tercelo', 'Three-A',
        'Tigar', 'Torero', 'Torque', 'Tourador', 'Toyo', 'Tracmax', 'Trazano', 'TRI-ACE', 'Triangle', 'Tunga',
        'Ultraforce', 'Unigrip', 'Uniroyal', 'Unistar', 'Venom Power', 'Viatti', 'Vinmax', 'Vittos', 'Vredestein', 'Wanda',
        'Wanli', 'Waterfall', 'WestLake', 'Wideway', 'Windforce', 'WINRUN', 'Yazd Tire', 'Yokohama', 'Zelda', 'ZETA',
        'ZMax', 'Амтел', 'АШК', 'Белшина', 'БрШЗ', 'Волтайр', 'Н.Камск', 'Росава',
    ];

    /** Кириллические буквы, похожие на латинские (в исходном списке встречается «NОRTEC» с русской О). */
    private const HOMOGLYPHS = [
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X',
        'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x',
    ];

    /** Чистит название: пробелы, кириллица внутри латинского названия. */
    public static function normalize(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (preg_match('/[A-Za-z]/', $name)) {
            $name = strtr($name, self::HOMOGLYPHS);
        }

        return $name;
    }

    /** @return list<string> Бренды без дублей (без учёта регистра; первое написание сохраняется). */
    public static function brands(): array
    {
        $out = [];
        foreach (self::BRANDS as $name) {
            $name = self::normalize($name);
            $out[mb_strtolower($name)] ??= $name;
        }

        return array_values($out);
    }

    public function run(): void
    {
        $existing = DB::table('tire_brands')->pluck('name')->map(fn ($n) => mb_strtolower($n))->flip();
        $now = now();
        $rows = [];
        foreach (self::brands() as $name) {
            if (! isset($existing[mb_strtolower($name)])) {
                $rows[] = ['name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($rows) {
            DB::table('tire_brands')->insert($rows);
        }
    }
}
