<?php

use App\Http\Controllers\Web\AcceptController;
use App\Http\Controllers\Web\Admin\IntegrationController;
use App\Http\Controllers\Web\Admin\LdapSettingsController;
use App\Http\Controllers\Web\Admin\SettingsController;
use App\Http\Controllers\Web\Admin\TariffController;
use App\Http\Controllers\Web\Admin\UserController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CarCatalogController;
use App\Http\Controllers\Web\ClientController;
use App\Http\Controllers\Web\ContractController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\HealthController;
use App\Http\Controllers\Web\InventoryController;
use App\Http\Controllers\Web\LocationController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\ScanController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\SetController;
use App\Http\Controllers\Web\TaskController;
use App\Http\Controllers\Web\TransferController;
use App\Http\Controllers\Web\TripController;
use App\Http\Controllers\Web\VehicleController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/search', [SearchController::class, 'index'])->middleware('can:search')->name('search');

    // Справочник авто для селектов марки/модели
    Route::get('/w/car-makes', [CarCatalogController::class, 'makes'])->name('cars.makes');
    Route::get('/w/car-models', [CarCatalogController::class, 'models'])->name('cars.models');

    // Клиенты и гараж
    Route::middleware('can:clients')->group(function () {
        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::post('/clients/{client}/merge', [ClientController::class, 'merge'])->middleware('can:fix')->name('clients.merge');
        Route::post('/clients/{client}/vehicles', [ClientController::class, 'storeVehicle'])->name('vehicles.store');

        // Раздел «Автомобили»
        Route::get('/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
        Route::post('/vehicles', [VehicleController::class, 'store'])->name('vehicles.create');
        Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])->name('vehicles.show');
        Route::put('/vehicles/{vehicle}', [VehicleController::class, 'update'])->name('vehicles.update');
        Route::post('/vehicles/{vehicle}/owner', [VehicleController::class, 'changeOwner'])->name('vehicles.owner');
        Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->middleware('can:fix')->name('vehicles.destroy');

        Route::put('/garage-sets/{garageSet}', [ClientController::class, 'updateGarageSet'])->name('garage.update');
        Route::post('/garage-sets/{garageSet}/move', [ClientController::class, 'moveGarageSet'])->name('garage.move');
        Route::post('/garage-sets/{garageSet}/retire', [ClientController::class, 'retireGarageSet'])->name('garage.retire');
        Route::get('/w/clients', [SearchController::class, 'lookup'])->name('clients.lookup');
    });

    // Приём на хранение
    Route::middleware('can:accept')->group(function () {
        Route::get('/accept', [AcceptController::class, 'create'])->name('accept.create');
        Route::post('/accept', [AcceptController::class, 'store'])->name('accept.store');
    });

    // Договоры и комплекты
    Route::get('/contracts', [ContractController::class, 'index'])->middleware('can:search')->name('contracts.index');
    Route::get('/contracts/{contract}', [ContractController::class, 'show'])->middleware('can:search')->name('contracts.show');
    Route::post('/contracts/{contract}/extend', [ContractController::class, 'extend'])->middleware('can:accept')->name('contracts.extend');
    Route::get('/contracts/{contract}/extensions/{extension}/print', [ContractController::class, 'extensionPrint'])->middleware('can:search')->name('contracts.extension.print');
    Route::post('/contracts/{contract}/tags', [ContractController::class, 'tags'])->middleware('can:print')->name('contracts.tags');
    Route::post('/contracts/{contract}/tags.zpl', [ContractController::class, 'zpl'])->middleware('can:print')->name('contracts.zpl');
    Route::get('/contracts/{contract}/receipt', [ContractController::class, 'receiptPage'])->middleware('can:print')->name('contracts.receipt');
    Route::post('/sets/{set}/order', [SetController::class, 'order'])->middleware('can:order-issue')->name('sets.order');
    Route::post('/sets/{set}/cancel-order', [SetController::class, 'cancelOrder'])->middleware('can:order-issue')->name('sets.cancel-order');
    Route::post('/sets/{set}/issue', [SetController::class, 'issue'])->middleware('can:issue')->name('sets.issue');
    Route::post('/sets/{set}/dispose', [SetController::class, 'dispose'])->middleware('can:fix')->name('sets.dispose');
    Route::post('/sets/{set}/photos', [SetController::class, 'uploadPhoto'])->middleware('can:accept')->name('sets.photos');
    Route::get('/photos/{photo}', [SetController::class, 'photo'])->middleware('can:search')->name('photos.show');

    // Экраны ТСД
    Route::get('/scan/place', [ScanController::class, 'placePage'])->middleware('can:place')->name('scan.place');
    Route::get('/scan/issue', [ScanController::class, 'issuePage'])->middleware('can:issue')->name('scan.issue');
    Route::get('/w/scan', [ScanController::class, 'lookup'])->middleware('can:search')->name('scan.lookup');
    Route::post('/w/place', [ScanController::class, 'place'])->middleware('can:place')->name('scan.place.store');

    // Перемещения
    Route::middleware('can:transfers')->group(function () {
        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/create', [TransferController::class, 'create'])->name('transfers.create');
        Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
        Route::get('/transfers/{transfer}', [TransferController::class, 'show'])->name('transfers.show');
        Route::post('/transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');
        Route::post('/transfers/{transfer}/sets', [TransferController::class, 'addSets'])->name('transfers.sets');
        Route::delete('/transfers/{transfer}/lines/{line}', [TransferController::class, 'removeLine'])->name('transfers.lines.remove');
        Route::get('/transfers/{transfer}/print', [TripController::class, 'printTransfer'])->name('transfers.print');

        // Пул перевозок и ведомости заданий на перевозку
        Route::get('/tasks/transport', [TripController::class, 'index'])->name('trips.index');
        Route::post('/trips', [TripController::class, 'store'])->name('trips.store');
        Route::get('/trips/{trip}', [TripController::class, 'show'])->name('trips.show');
        Route::put('/trips/{trip}', [TripController::class, 'update'])->name('trips.update');
        Route::post('/trips/{trip}/transfers', [TripController::class, 'addTransfers'])->name('trips.transfers.add');
        Route::delete('/trips/{trip}/transfers/{transfer}', [TripController::class, 'detach'])->name('trips.transfers.detach');
        Route::post('/trips/{trip}/cancel', [TripController::class, 'cancel'])->name('trips.cancel');
        Route::get('/trips/{trip}/print', [TripController::class, 'print'])->name('trips.print');
    });
    Route::post('/w/trips/{trip}/scan-out', [TripController::class, 'scanOut'])->middleware('can:ship-transfer')->name('trips.scan-out');
    Route::post('/trips/{trip}/lines/{line}/unscan', [TripController::class, 'unscan'])->middleware('can:ship-transfer')->name('trips.unscan');
    Route::post('/trips/{trip}/ship', [TripController::class, 'ship'])->middleware('can:ship-transfer')->name('trips.ship');
    Route::post('/w/trips/{trip}/scan-in', [TripController::class, 'scanIn'])->middleware('can:receive-transfer')->name('trips.scan-in');
    Route::post('/trips/{trip}/complete', [TripController::class, 'complete'])->middleware('can:receive-transfer')->name('trips.complete');
    Route::post('/w/transfers/{transfer}/scan-out', [TransferController::class, 'scanOut'])->middleware('can:ship-transfer')->name('transfers.scan-out');
    Route::post('/transfers/{transfer}/ship', [TransferController::class, 'ship'])->middleware('can:ship-transfer')->name('transfers.ship');
    Route::post('/w/transfers/{transfer}/scan-in', [TransferController::class, 'scanIn'])->middleware('can:receive-transfer')->name('transfers.scan-in');
    Route::post('/transfers/{transfer}/complete', [TransferController::class, 'complete'])->middleware('can:receive-transfer')->name('transfers.complete');

    // Задания
    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('/tasks/{task}/done', [TaskController::class, 'done'])->name('tasks.done');

    // Инвентаризация
    Route::middleware('can:inventory')->group(function () {
        Route::get('/inventories', [InventoryController::class, 'index'])->name('inventories.index');
        Route::post('/inventories', [InventoryController::class, 'store'])->name('inventories.store');
        Route::get('/inventories/{inventory}', [InventoryController::class, 'show'])->name('inventories.show');
        Route::post('/w/inventories/{inventory}/scan', [InventoryController::class, 'scan'])->name('inventories.scan');
        Route::post('/w/inventories/{inventory}/undo', [InventoryController::class, 'undo'])->name('inventories.undo');
        Route::post('/inventories/{inventory}/finish', [InventoryController::class, 'finish'])->name('inventories.finish');
    });

    // Отчёты
    Route::get('/reports', [ReportController::class, 'index'])->middleware('can:reports')->name('reports.index');

    // Справочники и администрирование
    Route::get('/locations', [LocationController::class, 'index'])->middleware('can:place')->name('locations.index');
    Route::get('/locations/{location}', [LocationController::class, 'show'])->middleware('can:place')->name('locations.show');
    Route::get('/locations/{location}/labels', [LocationController::class, 'labels'])->middleware('can:print')->name('locations.labels');
    Route::middleware('can:admin')->group(function () {
        Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
        Route::put('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
        Route::post('/locations/{location}/generate', [LocationController::class, 'generate'])->name('locations.generate');
        Route::put('/cells/{cell}', [LocationController::class, 'updateCell'])->name('cells.update');

        Route::get('/admin/users', [UserController::class, 'index'])->name('admin.users');
        Route::post('/admin/users', [UserController::class, 'store'])->name('admin.users.store');
        Route::put('/admin/users/{user}', [UserController::class, 'update'])->name('admin.users.update');
        Route::get('/admin/tariffs', [TariffController::class, 'index'])->name('admin.tariffs');
        Route::post('/admin/tariffs', [TariffController::class, 'store'])->name('admin.tariffs.store');
        Route::put('/admin/tariffs/pricing', [TariffController::class, 'updatePricing'])->name('admin.tariffs.pricing');
        Route::put('/admin/tariffs/{tariff}', [TariffController::class, 'update'])->name('admin.tariffs.update');
        Route::get('/admin/integration', [IntegrationController::class, 'index'])->name('admin.integration');
        Route::post('/admin/api-keys', [IntegrationController::class, 'storeKey'])->name('admin.keys.store');
        Route::post('/admin/api-keys/{key}/revoke', [IntegrationController::class, 'revokeKey'])->name('admin.keys.revoke');
        Route::get('/admin/settings', [SettingsController::class, 'index'])->name('admin.settings');
        Route::put('/admin/settings/cars', [SettingsController::class, 'updateCars'])->name('admin.settings.cars');
        Route::post('/admin/settings/cars/test', [SettingsController::class, 'testCars'])->name('admin.settings.cars.test');
        Route::post('/admin/settings/cars/sync', [SettingsController::class, 'syncCars'])->name('admin.settings.cars.sync');
        Route::put('/admin/settings/ldap', [LdapSettingsController::class, 'update'])->name('admin.settings.ldap');
        Route::post('/admin/settings/ldap/test', [LdapSettingsController::class, 'test'])->name('admin.settings.ldap.test');
        Route::post('/admin/settings/ldap/test-user', [LdapSettingsController::class, 'testUser'])->middleware('throttle:20,1')->name('admin.settings.ldap.test-user');
        Route::get('/admin/settings/ldap/groups', [LdapSettingsController::class, 'groups'])->name('admin.settings.ldap.groups');
        Route::post('/admin/settings/ldap/sync', [LdapSettingsController::class, 'sync'])->name('admin.settings.ldap.sync');
        Route::post('/admin/settings/ldap/mappings', [LdapSettingsController::class, 'storeMapping'])->name('admin.settings.ldap.mappings.store');
        Route::put('/admin/settings/ldap/mappings/{mapping}', [LdapSettingsController::class, 'updateMapping'])->name('admin.settings.ldap.mappings.update');
        Route::delete('/admin/settings/ldap/mappings/{mapping}', [LdapSettingsController::class, 'destroyMapping'])->name('admin.settings.ldap.mappings.destroy');
    });
});
