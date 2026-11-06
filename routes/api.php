<?php

use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\QueryController;
use App\Http\Controllers\Api\V1\ReferenceController;
use Illuminate\Support\Facades\Route;

/*
 * API v1 для 1С:УТ 10.3 (план, раздел 9.4). Аутентификация — X-Api-Key, формат — JSON/XML по Accept.
 */
Route::prefix('v1')->middleware(['api.key:onec', 'xml.body', 'idempotent', 'throttle:600,1'])->group(function () {
    // Справочники (1С → платформа)
    Route::put('clients/{onecGuid}', [ReferenceController::class, 'client'])->whereUuid('onecGuid');
    Route::put('services/{onecGuid}', [ReferenceController::class, 'service'])->whereUuid('onecGuid');
    Route::put('locations/{onecGuid}', [ReferenceController::class, 'location'])->whereUuid('onecGuid');

    // Запросы из 1С
    Route::get('clients/search', [QueryController::class, 'searchClients']);
    Route::get('clients/{id}/garage', [QueryController::class, 'garage']);
    Route::get('contracts/{number}', [QueryController::class, 'contract']);
    Route::get('sets/{qr}', [QueryController::class, 'set'])->where('qr', '.+');
    Route::get('stock', [QueryController::class, 'stock']);

    // Очередь событий (платформа → 1С)
    Route::get('integration/events', [IntegrationController::class, 'events']);
    Route::post('integration/ack', [IntegrationController::class, 'ack']);

    // Деньги (1С → платформа)
    Route::post('integration/payments', [IntegrationController::class, 'payment']);
    Route::put('contracts/{number}/debt', [IntegrationController::class, 'debt']);
});

/*
 * API v1 для онлайн-записи (zapis.diskimira.com): ключ с областью booking, JSON.
 * Поиск хранений клиента, заказ колёс к выдаче под запись, обмен гаражом, доверенные лица.
 */
Route::prefix('v1/booking')->middleware(['api.key:booking', 'idempotent', 'throttle:240,1'])->group(function () {
    Route::get('lookup', [BookingController::class, 'lookup']);
    Route::post('garage', [BookingController::class, 'importGarage']);
    Route::post('orders', [BookingController::class, 'placeOrder']);
    Route::get('orders/{code}', [BookingController::class, 'showOrder']);
    Route::patch('orders/{code}', [BookingController::class, 'updateOrder']);
    Route::delete('orders/{code}', [BookingController::class, 'cancelOrder']);
    Route::post('vehicles/{vehicle}/persons', [BookingController::class, 'addPerson'])->whereUuid('vehicle');
    Route::delete('vehicles/{vehicle}/persons/{person}', [BookingController::class, 'revokePerson'])->whereUuid('vehicle');
});
