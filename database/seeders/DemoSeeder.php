<?php

namespace Database\Seeders;

use App\Domain\Storage\AcceptanceService;
use App\Domain\Storage\IssueService;
use App\Domain\Transfer\TransferService;
use App\Domain\Users\Role;
use App\Domain\Warehouse\CellGenerator;
use App\Domain\Warehouse\LocationType;
use App\Domain\Warehouse\PlacementService;
use App\Models\Location;
use App\Models\StorageContract;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Демо-данные: 2 магазина, 2 склада, тарифы, пользователи всех ролей и хранения в разных статусах.
 * Пароль всех демо-пользователей: password
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $m01 = Location::firstOrCreate(['code' => 'М01'], ['name' => 'Диски Мира на Ленина', 'type' => LocationType::Store, 'address' => 'Сургут, пр. Ленина, 1']);
        $m05 = Location::firstOrCreate(['code' => 'М05'], ['name' => 'Диски Мира на 30 лет Победы', 'type' => LocationType::Store, 'address' => 'Сургут, ул. 30 лет Победы, 5']);
        $w02 = Location::firstOrCreate(['code' => '02'], ['name' => 'Склад Северный', 'type' => LocationType::Warehouse, 'address' => 'Сургут, Северная промзона']);
        $w03 = Location::firstOrCreate(['code' => '03'], ['name' => 'Склад Южный', 'type' => LocationType::Warehouse, 'address' => 'Сургут, ул. Индустриальная']);
        app(CellGenerator::class)->generate($w02, 1, 6, 20);
        app(CellGenerator::class)->generate($w03, 1, 3, 15, 2);

        $season = Tariff::firstOrCreate(['code' => 'ХР-СЕЗОН'], ['name' => 'Хранение комплекта, сезон (6 мес.)', 'price' => 4800, 'period' => 'season', 'months' => 6]);
        Tariff::firstOrCreate(['code' => 'ХР-МЕС'], ['name' => 'Хранение комплекта, помесячно', 'price' => 900, 'period' => 'month', 'months' => 1]);

        $users = [
            ['admin', 'Администратор', Role::Admin, null],
            ['manager', 'Руководитель М01', Role::StoreManager, $m01],
            ['seller', 'Продавец М01', Role::Seller, $m01],
            ['seller5', 'Продавец М05', Role::Seller, $m05],
            ['keeper', 'Кладовщик 02', Role::Storekeeper, $w02],
            ['driver', 'Водитель', Role::Driver, null],
        ];
        foreach ($users as [$login, $name, $role, $loc]) {
            User::updateOrCreate(['login' => $login], ['name' => $name, 'password' => 'password', 'role' => $role, 'location_id' => $loc?->id]);
        }

        if (StorageContract::exists()) {
            return;
        }
        $seller = User::where('login', 'seller')->first();
        Auth::login($seller);
        $accept = app(AcceptanceService::class);
        $people = [
            ['Иванов Иван Петрович', '+79120000001', 'А123ВС86', 'Kia', 'Rio', '205/55 R16 94H', true, 'summer'],
            ['Петрова Анна Сергеевна', '+79120000002', 'В456ОР86', 'Hyundai', 'Creta', '215/60 R17 96H', true, 'summer'],
            ['Сидоров Олег', '+79120000003', 'Е789КХ186', 'Toyota', 'RAV4', '225/65 R17 102T', false, 'all_season'],
            ['Кузнецова Мария', '+79120000004', 'М321ТТ86', 'Lada', 'Vesta', '185/65 R15 88T', true, 'summer'],
            ['Смирнов Алексей', '+79120000005', 'Н654УУ86', 'Haval', 'Jolion', '215/55 R18 95H', true, 'summer'],
        ];
        $contracts = [];
        foreach ($people as [$name, $phone, $plate, $make, $model, $size, $rims, $s]) {
            $contracts[] = $accept->accept([
                'store_id' => $m01->id, 'tariff_id' => $season->id,
                'client' => ['full_name' => $name, 'phone' => $phone, 'consent_sms' => true],
                'vehicle' => ['plate' => $plate, 'make' => $make, 'model' => $model],
                'sets' => [['qty' => 4, 'tire_size' => $size, 'with_rims' => $rims, 'condition' => 'used', 'season' => $s]],
            ], $seller);
        }
        // Три комплекта увозим на склад 02 и размещаем, один заказываем к выдаче в М05
        $transfers = app(TransferService::class);
        $sets = collect($contracts)->take(3)->map(fn ($c) => $c->sets()->first());
        $t = $transfers->create($m01, $w02, $sets->pluck('id')->all(), $seller, 'Рейс 1');
        foreach ($sets as $set) {
            $transfers->scanOut($t, $set->qr_code);
        }
        $transfers->ship($t);
        foreach ($sets as $set) {
            $transfers->scanIn($t, $set->qr_code);
        }
        $transfers->complete($t);
        $placement = app(PlacementService::class);
        foreach ($sets->take(2) as $set) {
            $fresh = $set->fresh();
            $placement->place($fresh, $placement->suggest($fresh)->address);
        }
        app(IssueService::class)->order($sets->first()->fresh(), $m05, now()->addDays(2), $seller);
        Auth::logout();
    }
}
