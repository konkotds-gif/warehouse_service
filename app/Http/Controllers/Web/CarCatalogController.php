<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CarMake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Справочник авто для селектов марки и модели (мастер приёма, гараж клиента). */
class CarCatalogController extends Controller
{
    public function makes(): JsonResponse
    {
        return response()->json(CarMake::forSelect())->header('Cache-Control', 'private, max-age=600');
    }

    public function models(Request $request): JsonResponse
    {
        $make = CarMake::findByName($request->query('make'));
        $models = $make ? $make->models()->where('is_active', true)->orderBy('name')->pluck('name')->unique()->values() : [];

        return response()->json($models)->header('Cache-Control', 'private, max-age=600');
    }
}
