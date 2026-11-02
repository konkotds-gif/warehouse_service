<?php

use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\QueryController;
use App\Http\Controllers\Api\V1\ReferenceController;
use Illuminate\Support\Facades\Route;

/*
 * API v1 для 1С:УТ 10.3 (план, раздел 9.4). Аутентификация — X-Api-Key, формат — JSON/XML по Accept.
 */
Route::prefix('v1')->middleware(['api.key', 'xml.body', 'idempotent', 'throttle:600,1'])->group(function () {
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
