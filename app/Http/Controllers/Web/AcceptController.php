<?php

namespace App\Http\Controllers\Web;

use App\Domain\Storage\AcceptanceService;
use App\Domain\Storage\ContractConfirmation;
use App\Domain\Storage\GarageSetState;
use App\Domain\Storage\Pricing;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Client;
use App\Models\GarageSet;
use App\Models\Location;
use App\Models\Tariff;
use App\Models\TireBrand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Мастер приёма: Клиент · Авто/гараж · Комплект · Печать (техдок, 7.5). */
class AcceptController extends Controller
{
    public function create(Request $request): Response
    {
        $client = $request->filled('client') ? Client::find($request->query('client')) : null;
        $preselect = null;
        if ($request->filled('garage_set') && ($gs = GarageSet::find($request->query('garage_set')))) {
            $preselect = ['vehicle_id' => $gs->vehicle_id, 'garage_set_id' => $gs->id];
            $client ??= $gs->vehicle->client;
        } elseif ($request->filled('vehicle')) {
            $preselect = ['vehicle_id' => $request->query('vehicle')];
        }
        $user = $request->user();

        return Inertia::render('Accept/Wizard', [
            'stores' => Location::active()->stores()->orderBy('code')->get()->map(fn ($l) => Present::location($l))->all(),
            'defaultStoreId' => $user->location?->isStore() ? $user->location_id : null,
            'prices' => Tariff::where('is_active', true)->whereNotNull('diameter_from')->orderBy('diameter_from')
                ->get(['id', 'code', 'name', 'diameter_from', 'diameter_to', 'price']),
            'pricing' => Pricing::settings(),
            'client' => $client ? Present::client($client) : null,
            'garage' => $client ? array_map(fn ($v) => $v + ['garage_sets' => array_values(array_filter($v['garage_sets'],
                fn ($g) => $g['state'] !== GarageSetState::Retired->value))], Present::garage($client)) : [],
            'preselect' => $preselect,
            'tireBrands' => TireBrand::activeNames(),
        ]);
    }

    public function store(Request $request, AcceptanceService $service, ContractConfirmation $confirmation): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'client_id' => ['nullable', 'uuid'],
            'client' => ['nullable', 'array'],
            'client.full_name' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client.phone' => ['nullable', 'string', 'max:32'],
            'client.consent_sms' => ['nullable', 'boolean'],
            'client.consent_pd' => ['nullable', 'boolean'],
            'vehicle_id' => ['nullable', 'uuid'],
            'vehicle' => ['nullable', 'array'],
            'months' => ['nullable', 'integer', 'min:1', 'max:'.Pricing::settings()['max_months']],
            'notes' => ['nullable', 'string', 'max:255'],
            'sets' => ['required', 'array', 'min:1', 'max:4'],
            'sets.*.qty' => ['required', 'integer', 'min:1', 'max:8'],
            'sets.*.tire_size' => ['required', 'string', 'max:40'],
            'sets.*.with_rims' => ['required', 'boolean'],
            'sets.*.condition' => ['required', 'in:used,new'],
        ], [
            'client.full_name.required_without' => 'Укажите ФИО клиента',
            'sets.*.tire_size.required' => 'Укажите размер шин: ширина, профиль, диаметр',
            'sets.*.with_rims.required' => 'Укажите: с дисками или без',
            'sets.*.condition.required' => 'Укажите: БУ или НОВЫЕ',
        ]);
        $user = $request->user();
        if (! $user->isAdmin() && $user->location_id && $user->location?->isStore() && (int) $data['store_id'] !== $user->location_id) {
            abort(403, 'Приём только в своём магазине');
        }
        $contract = $service->accept($request->all(), $user);
        // Код подтверждения клиенту по SMS (вне транзакции приёма: сбой шлюза не отменяет договор)
        $sms = $confirmation->start($contract, $user);

        $redirect = redirect()->route('contracts.show', ['contract' => $contract, 'print' => 1])
            ->with('success', "Договор {$contract->number} оформлен. Напечатайте бирки: {$contract->sets->sum('qty')} шт."
                .($sms && $sms['sent'] ? ' '.$sms['message'].'.' : ''));

        return $sms && ! $sms['sent'] ? $redirect->with('error', $sms['message']) : $redirect;
    }
}
