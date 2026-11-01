<?php

namespace App\Http\Controllers\Web;

use App\Models\Location;
use Illuminate\Http\Request;

/** Общие помощники веб-контроллеров. */
trait Concerns
{
    /** Текущая локация: из запроса (для администратора/руководителя) или локация пользователя. */
    protected function currentLocation(Request $request, ?string $param = 'location'): ?Location
    {
        $user = $request->user();
        if ($param && $request->filled($param) && ($user->isAdmin() || $user->location_id === null || $user->hasAbility('reports'))) {
            return Location::find($request->integer($param));
        }

        return $user->location;
    }

    protected function locations(): array
    {
        return Location::active()->orderBy('type')->orderBy('code')->get()
            ->map(fn ($l) => ['id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'type' => $l->type->value, 'label' => $l->label()])
            ->all();
    }
}
