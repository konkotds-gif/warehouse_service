<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Cars\CarCatalogSync;
use App\Http\Controllers\Controller;
use App\Models\CarMake;
use App\Models\CarModel;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Настройки платформы: справочник марок и моделей авто, SMS (SMSC), вход через Active Directory. */
class SettingsController extends Controller
{
    public function index(CarCatalogSync $sync): Response
    {
        $key = $sync->key();
        $fromEnv = $key && ! Setting::getSecret(CarCatalogSync::KEY);

        return Inertia::render('Admin/Settings', [
            'cars' => [
                'api_url' => Setting::get(CarCatalogSync::URL) ?: (config('services.car_catalog.url') ?: CarCatalogSync::DEFAULT_URL),
                'key_set' => (bool) $key,
                'key_mask' => $key ? mb_substr($key, 0, 6).'…'.mb_substr($key, -4) : null,
                'key_from_env' => $fromEnv,
                'last_sync' => Setting::get(CarCatalogSync::LAST),
                'last_success_at' => Setting::get(CarCatalogSync::LAST_SUCCESS),
                'version' => Setting::get(CarCatalogSync::VERSION),
                'makes' => CarMake::where('is_active', true)->count(),
                'models' => CarModel::where('is_active', true)->count(),
                'schedule' => 'ежедневно в 04:30 (местное время)',
            ],
            'scheduler_last_run_at' => Setting::get('scheduler.last_run_at'),
            'ldap' => LdapSettingsController::props(),
            'sms' => SmsSettingsController::props(),
        ]);
    }

    public function updateCars(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_url' => ['required', 'url:https', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'remove_key' => ['nullable', 'boolean'],
        ], ['api_url.url' => 'Укажите адрес API, начинающийся с https://']);

        Setting::put(CarCatalogSync::URL, rtrim($data['api_url'], '/'));
        if ($request->boolean('remove_key')) {
            Setting::putSecret(CarCatalogSync::KEY, null);
        } elseif (filled($data['api_key'] ?? null)) {
            Setting::putSecret(CarCatalogSync::KEY, trim($data['api_key']));
            Setting::put(CarCatalogSync::VERSION, null); // другой ключ/источник — следующая загрузка полная
        }

        return back()->with('success', 'Настройки справочника авто сохранены');
    }

    public function testCars(CarCatalogSync $sync): RedirectResponse
    {
        $result = $sync->test();

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function syncCars(Request $request, CarCatalogSync $sync): RedirectResponse
    {
        @set_time_limit(300);
        $result = $sync->run($request->boolean('force'));

        return back()->with(in_array($result['status'], ['updated', 'not_modified'], true) ? 'success' : 'error', $result['message']);
    }
}
