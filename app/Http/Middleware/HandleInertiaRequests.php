<?php

namespace App\Http\Middleware;

use App\Domain\Storage\Damage;
use App\Domain\Storage\Season;
use App\Domain\Storage\SetStatus;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'csrf_token' => fn () => csrf_token(),
            'app' => [
                'name' => config('app.name'),
                'timezone' => config('app.display_timezone'),
            ],
            'auth' => $user ? [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'login' => $user->login,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'location' => $user->location ? ['id' => $user->location->id, 'code' => $user->location->code,
                        'name' => $user->location->name, 'type' => $user->location->type->value] : null,
                ],
                'abilities' => $user->role->abilities(),
            ] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'secret' => fn () => $request->session()->get('secret'),
            ],
            'dict' => fn () => [
                'statuses' => SetStatus::dictionary(),
                'damages' => Damage::dictionary(),
                'seasons' => collect(Season::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->all(),
            ],
        ];
    }
}
