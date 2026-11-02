<?php

namespace Tests\Support;

use App\Domain\Storage\AcceptanceService;
use App\Domain\Users\Role;
use App\Domain\Warehouse\CellGenerator;
use App\Domain\Warehouse\LocationType;
use App\Models\Location;
use App\Models\StorageContract;
use App\Models\Tariff;
use App\Models\User;
use App\Models\WheelSet;

trait Fixtures
{
    protected function store(string $code = 'М01', string $name = 'Магазин 1'): Location
    {
        return Location::create(['code' => $code, 'name' => $name, 'type' => LocationType::Store]);
    }

    protected function warehouse(string $code = '02', int $rows = 2, int $cells = 3, int $capacity = 1): Location
    {
        $w = Location::create(['code' => $code, 'name' => 'Склад '.$code, 'type' => LocationType::Warehouse]);
        app(CellGenerator::class)->generate($w, 1, $rows, $cells, $capacity);

        return $w;
    }

    protected function user(Role $role = Role::Admin, ?Location $location = null): User
    {
        return User::factory()->role($role)->create(['location_id' => $location?->id]);
    }

    protected function tariff(): Tariff
    {
        return Tariff::create(['code' => 'ХР-СЕЗОН', 'name' => 'Хранение, сезон', 'price' => 4800, 'period' => 'season', 'months' => 6]);
    }

    protected function acceptData(Location $store, array $override = []): array
    {
        return array_replace_recursive([
            'store_id' => $store->id,
            'client' => ['full_name' => 'Иванов Иван', 'phone' => '8 (912) 345-67-89', 'consent_sms' => true],
            'vehicle' => ['plate' => 'a123bc 86', 'make' => 'Kia', 'model' => 'Rio'],
            'sets' => [[
                'qty' => 4, 'tire_size' => '205/55r16 94h', 'with_rims' => true, 'condition' => 'used', 'season' => 'summer',
            ]],
        ], $override);
    }

    protected function accept(Location $store, array $override = [], ?User $user = null): StorageContract
    {
        return app(AcceptanceService::class)->accept($this->acceptData($store, $override), $user);
    }

    protected function firstSet(StorageContract $contract): WheelSet
    {
        return $contract->sets()->first();
    }
}
