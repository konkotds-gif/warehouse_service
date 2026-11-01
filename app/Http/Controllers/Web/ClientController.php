<?php

namespace App\Http\Controllers\Web;

use App\Domain\Integration\OneC\Outbox;
use App\Domain\Storage\AcceptanceService;
use App\Domain\Storage\GarageSetState;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\DomainError;
use App\Domain\Support\Normalizer;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Client;
use App\Models\GarageSet;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Клиенты и гараж клиента (машины и закреплённые комплекты). */
class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q'));
        $clients = Client::withCount('vehicles')
            ->when($q !== '', function ($query) use ($q) {
                $digits = Normalizer::phoneDigits($q);
                $query->where(fn ($w) => $w->where('full_name', 'like', "%{$q}%")
                    ->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', "%{$digits}%"))
                    ->orWhereIn('id', Vehicle::where('plate', 'like', '%'.Normalizer::plate($q).'%')->select('client_id')));
            })
            ->orderBy('full_name')->paginate(30)->withQueryString();

        return Inertia::render('Clients/Index', [
            'q' => $q,
            'clients' => $clients->through(fn ($c) => Present::client($c) + ['vehicles_count' => $c->vehicles_count]),
        ]);
    }

    public function show(Client $client): Response
    {
        $contracts = $client->contracts()->with(['vehicle', 'store', 'sets.cell', 'sets.location', 'sets.transfer'])->limit(50)->get();

        return Inertia::render('Clients/Show', [
            'client' => Present::client($client),
            'garage' => Present::garage($client),
            'contracts' => $contracts->map(fn ($c) => Present::contract($c))->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateClient($request);
        $phone = Normalizer::phone($data['phone'] ?? null);
        if ($phone && ($existing = Client::where('phone', $phone)->first())) {
            return redirect()->route('clients.show', $existing)->with('error', 'Клиент с таким телефоном уже есть');
        }
        $client = DB::transaction(function () use ($data) {
            $client = Client::create($data);
            app(Outbox::class)->record('client.created', AcceptanceService::clientPayload($client));

            return $client;
        });

        return redirect()->route('clients.show', $client)->with('success', 'Клиент создан');
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->update($this->validateClient($request));

        return back()->with('success', 'Данные клиента сохранены');
    }

    public function storeVehicle(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'plate' => ['required', 'string', 'max:16'], 'make' => ['required', 'string', 'max:64'], 'model' => ['required', 'string', 'max:64'],
        ]);
        if ($client->vehicles()->where('plate', Normalizer::plate($data['plate']))->exists()) {
            throw DomainError::invalid('Такой госномер у клиента уже есть', 'plate');
        }
        $client->vehicles()->create($data);

        return back()->with('success', 'Автомобиль добавлен');
    }

    public function updateVehicle(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $vehicle->update($request->validate([
            'plate' => ['required', 'string', 'max:16'], 'make' => ['required', 'string', 'max:64'], 'model' => ['required', 'string', 'max:64'],
        ]));

        return back()->with('success', 'Автомобиль сохранён');
    }

    public function updateGarageSet(Request $request, GarageSet $garageSet): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'season' => ['nullable', 'string'],
            'qty' => ['required', 'integer', 'min:1', 'max:8'],
            'tire_size' => ['required', 'string'],
            'with_rims' => ['required', 'boolean'],
            'condition' => ['required', 'in:used,new'],
            'brand_model' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $data['tire_size'] = Normalizer::tireSize($data['tire_size']);
        if (! Normalizer::isValidTireSize($data['tire_size'])) {
            throw DomainError::invalid('Неверный формат размера', 'tire_size');
        }
        $garageSet->update($data);

        return back()->with('success', 'Комплект в гараже сохранён');
    }

    /** Перенести комплект на другую машину клиента (купил новую машину, колёса подошли). */
    public function moveGarageSet(Request $request, GarageSet $garageSet): RedirectResponse
    {
        $vehicle = Vehicle::findOrFail($request->validate(['vehicle_id' => ['required', 'uuid']])['vehicle_id']);
        if ($vehicle->client_id !== $garageSet->vehicle->client_id) {
            throw DomainError::invalid('Можно перенести только на машину того же клиента');
        }
        $garageSet->update(['vehicle_id' => $vehicle->id]);

        return back()->with('success', 'Комплект перенесён на '.$vehicle->title());
    }

    /** Отметить «продан / утилизирован» — комплект скрывается из подсказок. */
    public function retireGarageSet(GarageSet $garageSet): RedirectResponse
    {
        if ($garageSet->wheelSets()->whereIn('status', SetStatus::activeValues())->exists()) {
            throw new DomainError('Комплект сейчас на хранении — сначала выдайте его');
        }
        $garageSet->update(['state' => GarageSetState::Retired]);

        return back()->with('success', 'Комплект отмечен как проданный / утилизированный');
    }

    /** Объединить дубль клиента с текущим: машины и договоры переносятся, дубль удаляется (мягко). */
    public function merge(Request $request, Client $client): RedirectResponse
    {
        $other = Client::findOrFail($request->validate(['other_id' => ['required', 'uuid']])['other_id']);
        if ($other->id === $client->id) {
            throw DomainError::invalid('Нельзя объединить клиента с самим собой');
        }
        DB::transaction(function () use ($client, $other) {
            $other->vehicles()->update(['client_id' => $client->id]);
            $other->contracts()->update(['client_id' => $client->id]);
            $client->update([
                'phone' => $client->phone ?: $other->phone,
                'email' => $client->email ?: $other->email,
                'onec_guid' => $client->onec_guid ?: $other->onec_guid,
            ]);
            $other->update(['onec_guid' => null]);
            $other->delete();
        });

        return redirect()->route('clients.show', $client)->with('success', 'Клиенты объединены');
    }

    private function validateClient(Request $request): array
    {
        return $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'consent_sms' => ['boolean'],
            'consent_pd' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
