<?php

namespace App\Http\Controllers\Web;

use App\Domain\Family\VehiclePersons;
use App\Domain\Storage\GarageSetState;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\DomainError;
use App\Domain\Support\Normalizer;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Client;
use App\Models\StorageContract;
use App\Models\Vehicle;
use App\Models\VehicleOwnerChange;
use App\Models\VehiclePerson;
use App\Models\WheelSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Раздел «Автомобили»: все машины клиентов, карточка авто с владельцем, комплектами гаража,
 * договорами хранения и историей владельцев.
 */
class VehicleController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q'));
        $only = (string) $request->query('only');
        $active = SetStatus::activeValues();

        $vehicles = Vehicle::with('client')
            ->withCount([
                'garageSets as garage_sets_count' => fn ($g) => $g->where('state', '!=', GarageSetState::Retired->value),
                'contracts as contracts_count',
            ])
            ->addSelect(['stored_sets' => WheelSet::selectRaw('count(*)')
                ->whereIn('status', $active)
                ->whereIn('contract_id', StorageContract::select('id')->whereColumn('vehicle_id', 'vehicles.id')),
            ])
            ->when($q !== '', function ($query) use ($q) {
                $plate = Normalizer::plate($q);
                $digits = Normalizer::phoneDigits($q);
                $query->where(fn ($w) => $w
                    ->when($plate, fn ($w) => $w->orWhere('plate', 'like', '%'.$plate.'%')->orWhere('vin', 'like', '%'.mb_strtoupper(str_replace(' ', '', $q)).'%'))
                    ->orWhere('make', 'like', "%{$q}%")
                    ->orWhere('model', 'like', "%{$q}%")
                    ->orWhereRaw("concat_ws(' ', make, model) like ?", ["%{$q}%"])
                    ->orWhereIn('client_id', Client::select('id')->where(fn ($c) => $c->where('full_name', 'like', "%{$q}%")
                        ->when(strlen($digits) >= 4, fn ($c) => $c->orWhere('phone', 'like', "%{$digits}%")))));
            })
            ->when($only === 'stored', fn ($query) => $query->whereIn('id', StorageContract::select('vehicle_id')
                ->whereNotNull('vehicle_id')
                ->whereIn('id', WheelSet::select('contract_id')->whereIn('status', $active))))
            ->when($only === 'no_plate', fn ($query) => $query->whereNull('plate'))
            ->orderBy('make')->orderBy('model')->orderBy('plate')
            ->paginate(30)->withQueryString();

        return Inertia::render('Vehicles/Index', [
            'filters' => ['q' => $q, 'only' => $only],
            'vehicles' => $vehicles->through(fn (Vehicle $v) => Present::vehicle($v) + [
                'client' => $v->client ? ['id' => $v->client->id, 'full_name' => $v->client->full_name, 'phone' => $v->client->phone] : null,
                'garage_sets_count' => (int) $v->garage_sets_count,
                'contracts_count' => (int) $v->contracts_count,
                'stored_sets' => (int) $v->stored_sets,
            ]),
        ]);
    }

    public function show(Vehicle $vehicle): Response
    {
        $vehicle->load(['client', 'garageSets' => fn ($q) => $q->orderBy('created_at'),
            'garageSets.wheelSets.cell', 'garageSets.wheelSets.location', 'garageSets.wheelSets.transfer',
            'ownerChanges.fromClient', 'ownerChanges.toClient', 'ownerChanges.user']);
        $contracts = $vehicle->contracts()->with(['client', 'store', 'sets.cell', 'sets.location', 'sets.transfer'])->limit(100)->get();

        return Inertia::render('Vehicles/Show', [
            'vehicle' => Present::vehicle($vehicle),
            'client' => $vehicle->client ? Present::client($vehicle->client) : null,
            'garageSets' => $vehicle->garageSets->map(fn ($g) => Present::garageSet($g))->all(),
            'contracts' => $contracts->map(fn ($c) => Present::contract($c))->all(),
            'owners' => $vehicle->ownerChanges->map(fn (VehicleOwnerChange $o) => [
                'id' => $o->id, 'at' => $o->at->toIso8601String(),
                'from' => $o->fromClient ? ['id' => $o->fromClient->id, 'full_name' => $o->fromClient->full_name] : null,
                'to' => $o->toClient ? ['id' => $o->toClient->id, 'full_name' => $o->toClient->full_name] : null,
                'sets_moved' => $o->sets_moved, 'note' => $o->note, 'user' => $o->user?->name,
            ])->all(),
            'inStorage' => $vehicle->hasSetsInStorage(),
            'persons' => VehiclePersons::present($vehicle),
        ]);
    }

    /** Новый автомобиль из раздела «Автомобили» — сразу с владельцем. */
    public function store(Request $request): RedirectResponse
    {
        $client = Client::findOrFail($request->validate(['client_id' => ['required', 'uuid']])['client_id']);
        $data = self::validated($request);
        $this->ensureUniquePlate($data['plate'], null);
        $vehicle = $client->vehicles()->create($data);

        return redirect()->route('vehicles.show', $vehicle)->with('success', 'Автомобиль добавлен');
    }

    public function update(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $data = self::validated($request);
        if (Normalizer::plate($data['plate']) !== $vehicle->plate) {
            $this->ensureUniquePlate($data['plate'], $vehicle);
        }
        $vehicle->update($data);

        return back()->with('success', 'Автомобиль сохранён');
    }

    /**
     * Смена владельца: авто продали или переоформили на другого клиента.
     * Договоры остаются за прежним владельцем (это история), комплекты гаража — по выбору:
     * переходят вместе с машиной или отмечаются «не перешли» (скрываются из подсказок).
     */
    public function changeOwner(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'sets_moved' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $to = Client::findOrFail($data['client_id']);
        if ($to->id === $vehicle->client_id) {
            throw DomainError::invalid('Этот клиент уже владелец автомобиля', 'client_id');
        }
        if ($vehicle->hasSetsInStorage()) {
            throw new DomainError('Колёса этого автомобиля сейчас на хранении — сначала выдайте их прежнему владельцу');
        }
        if ($vehicle->plate && $to->vehicles()->where('plate', $vehicle->plate)->exists()) {
            throw DomainError::invalid('У нового владельца уже есть авто с таким госномером — объедините карточки', 'client_id');
        }

        DB::transaction(function () use ($vehicle, $to, $data) {
            VehicleOwnerChange::create([
                'vehicle_id' => $vehicle->id, 'from_client_id' => $vehicle->client_id, 'to_client_id' => $to->id,
                'sets_moved' => $data['sets_moved'], 'user_id' => Auth::id(), 'note' => $data['note'] ?? null, 'at' => now(),
            ]);
            if (! $data['sets_moved']) {
                $vehicle->garageSets()->where('state', '!=', GarageSetState::Retired->value)
                    ->update(['state' => GarageSetState::Retired->value]);
            }
            $vehicle->update(['client_id' => $to->id]);
            // Доверенности давал прежний владелец — при смене владельца они теряют силу
            app(VehiclePersons::class)->revokeAll($vehicle, 'owner_changed');
        });

        return back()->with('success', 'Владелец изменён: '.$to->full_name);
    }

    /**
     * Добавить доверенное лицо (семейный аккаунт): существующего клиента или нового по телефону и имени.
     * Доверенное лицо видит машину в своём гараже, сдаёт колёса на свой договор и забирает их.
     */
    public function addPerson(Request $request, Vehicle $vehicle, VehiclePersons $persons): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'uuid'],
            'phone' => ['nullable', 'string', 'max:32'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $person = ! empty($data['client_id']) ? Client::findOrFail($data['client_id']) : null;
        $p = $persons->add($vehicle, $person, $data, 'staff', $request->user(), $data['note'] ?? null);

        return back()->with('success', 'Доверенное лицо добавлено: '.$p->client->full_name);
    }

    public function revokePerson(Vehicle $vehicle, VehiclePerson $person, VehiclePersons $persons): RedirectResponse
    {
        abort_if($person->vehicle_id !== $vehicle->id, 404);
        $persons->revoke($person, 'staff');

        return back()->with('success', 'Доверенность отозвана');
    }

    /** Удалить ошибочную карточку авто (мягко). Договоры сохраняют ссылку на неё. */
    public function destroy(Vehicle $vehicle): RedirectResponse
    {
        if ($vehicle->hasSetsInStorage()) {
            throw new DomainError('Колёса этого автомобиля на хранении — удалить нельзя');
        }
        $client = $vehicle->client_id;
        $vehicle->delete();

        return redirect()->route('clients.show', $client)->with('success', 'Автомобиль удалён');
    }

    /**
     * Проверка полей автомобиля (общая для раздела «Автомобили» и карточки клиента).
     * Госномер обязателен, кроме нового автомобиля без номеров (no_plate).
     *
     * @return array<string, mixed>
     */
    public static function validated(Request $request): array
    {
        $data = $request->validate([
            'no_plate' => ['nullable', 'boolean'],
            'plate' => ['required_unless:no_plate,true,1', 'nullable', 'string', 'max:16'],
            'make' => ['required', 'string', 'max:64'],
            'model' => ['required', 'string', 'max:64'],
            'vin' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\- ]+$/'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'color' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'plate.required_unless' => 'Укажите госномер или отметьте «Без номеров»',
            'vin.regex' => 'VIN — только латинские буквы и цифры',
        ]);
        if ($request->boolean('no_plate')) {
            $data['plate'] = null;
        }
        unset($data['no_plate']);

        return $data;
    }

    /** Один госномер — одна карточка авто на всю базу (иначе это та же машина у другого владельца). */
    private function ensureUniquePlate(?string $plate, ?Vehicle $self): void
    {
        $plate = Normalizer::plate($plate);
        if (! $plate) {
            return;
        }
        $other = Vehicle::with('client')->where('plate', $plate)
            ->when($self, fn ($q) => $q->where('id', '!=', $self->id))->first();
        if ($other) {
            throw DomainError::invalid('Авто с таким госномером уже есть'
                .($other->client ? ' у клиента '.$other->client->full_name : '').' — откройте его карточку и смените владельца', 'plate');
        }
    }
}
