<?php

namespace App\Http\Controllers\Web;

use App\Domain\Labels\TagRenderer;
use App\Domain\Storage\SetStatus;
use App\Domain\Warehouse\CellGenerator;
use App\Domain\Warehouse\LocationType;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Location;
use App\Models\StorageCell;
use App\Models\WheelSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Справочник локаций, рядов и ячеек; карта склада; этикетки ячеек. */
class LocationController extends Controller
{
    public function index(): Response
    {
        $occupied = WheelSet::whereIn('status', StorageCell::OCCUPYING_STATUSES)->whereNotNull('current_cell_id')
            ->selectRaw('current_location_id, count(*) c')->groupBy('current_location_id')->pluck('c', 'current_location_id');
        $active = WheelSet::whereIn('status', SetStatus::activeValues())
            ->selectRaw('current_location_id, count(*) c')->groupBy('current_location_id')->pluck('c', 'current_location_id');

        return Inertia::render('Locations/Index', [
            'locations' => ($all = Location::withCount('cells')->withSum('cells', 'capacity')->orderBy('type')->orderBy('code')->get())
                ->map(fn ($l) => Present::location($l) + [
                    'same_building' => $l->building ? $all->where('building', $l->building)->where('id', '!=', $l->id)->pluck('code')->values()->all() : [],
                    'cells_count' => $l->cells_count,
                    'capacity' => (int) $l->cells_sum_capacity,
                    'occupied' => (int) ($occupied[$l->id] ?? 0),
                    'sets' => (int) ($active[$l->id] ?? 0),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $mates = $data['same_building'] ?? [];
        unset($data['same_building']);
        $location = Location::create($data);
        $this->syncBuilding($location, $mates);

        return redirect()->route('locations.show', $location)->with('success', 'Локация создана');
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $data = $this->validated($request, $location);
        $mates = $data['same_building'] ?? null;
        unset($data['same_building']);
        $location->update($data);
        if ($mates !== null) {
            $this->syncBuilding($location, $mates);
        }

        return back()->with('success', 'Локация сохранена');
    }

    public function show(Location $location): Response
    {
        $location->load(['rows.cells']);
        $sets = WheelSet::with('contract')->where('current_location_id', $location->id)
            ->whereIn('status', StorageCell::OCCUPYING_STATUSES)->whereNotNull('current_cell_id')->get()->groupBy('current_cell_id');

        return Inertia::render('Locations/Show', [
            'location' => Present::location($location) + [
                'same_building' => $location->buildingMates()->map(fn ($l) => ['id' => $l->id, 'code' => $l->code, 'label' => $l->label()])->values()->all(),
            ],
            'others' => Location::whereKeyNot($location->id)->orderBy('type')->orderBy('code')->get()
                ->map(fn ($l) => ['id' => $l->id, 'label' => $l->label(), 'type_label' => $l->type->label()])->all(),
            'rows' => $location->rows->map(fn ($r) => [
                'id' => $r->id, 'row_no' => $r->row_no, 'title' => $r->title,
                'cells' => $r->cells->map(fn ($c) => [
                    'id' => $c->id, 'cell_no' => $c->cell_no, 'address' => $c->address, 'capacity' => $c->capacity,
                    'is_blocked' => $c->is_blocked,
                    'sets' => ($sets[$c->id] ?? collect())->map(fn ($s) => ['qr_code' => $s->qr_code, 'contract_id' => $s->contract_id,
                        'status' => $s->status->value])->values()->all(),
                ])->all(),
            ])->all(),
            'buffer' => WheelSet::with(['contract.vehicle', 'location', 'cell', 'transfer'])->where('current_location_id', $location->id)
                ->whereNull('current_cell_id')->whereIn('status', SetStatus::activeValues())->limit(200)->get()
                ->map(fn ($s) => Present::set($s))->all(),
        ]);
    }

    public function generate(Request $request, Location $location, CellGenerator $generator): RedirectResponse
    {
        $d = $request->validate([
            'row_from' => ['required', 'integer', 'min:1', 'max:99'], 'row_to' => ['required', 'integer', 'min:1', 'max:99'],
            'cells' => ['required', 'integer', 'min:1', 'max:99'], 'cell_from' => ['nullable', 'integer', 'min:1', 'max:99'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);
        $n = $generator->generate($location, $d['row_from'], $d['row_to'], $d['cells'], $d['capacity'] ?? 1, $d['cell_from'] ?? 1);

        return back()->with('success', "Создано ячеек: {$n}");
    }

    public function updateCell(Request $request, StorageCell $cell): RedirectResponse
    {
        $cell->update($request->validate(['capacity' => ['required', 'integer', 'min:1', 'max:10'], 'is_blocked' => ['required', 'boolean']]));

        return back()->with('success', "Ячейка {$cell->address} сохранена");
    }

    /** Этикетки ячеек: QR с адресом и крупный текст (план, раздел 11). */
    public function labels(Request $request, Location $location, TagRenderer $renderer)
    {
        $cells = StorageCell::where('location_id', $location->id)
            ->when($request->query('row'), fn ($q, $row) => $q->whereHas('row', fn ($r) => $r->where('row_no', $row)))
            ->orderBy('address')->get();
        if ($request->query('format') === 'zpl') {
            return response($cells->map(fn ($c) => $renderer->cellZpl($c))->implode(''), 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="cells-'.$location->code.'.zpl"',
            ]);
        }

        return response()->view('print.cells', [
            'location' => $location,
            'cells' => $cells->map(fn ($c) => ['address' => $c->address, 'svg' => $renderer->qrSvg($c->address, 200)]),
        ]);
    }

    /**
     * Склад и магазин в одном здании: у точек одного здания общий ключ building.
     * Отмеченные точки переводятся в здание этой точки; снятые отметки — исключают точку из здания
     * (если в здании остаётся одна точка, признак снимается).
     *
     * @param  list<int>  $mateIds
     */
    private function syncBuilding(Location $location, array $mateIds): void
    {
        $mateIds = array_values(array_diff(array_map('intval', $mateIds), [$location->id]));
        DB::transaction(function () use ($location, $mateIds) {
            $old = $location->building;
            if ($mateIds === []) {
                $location->update(['building' => null]);
            } else {
                $key = $old ?? 'B'.$location->id.'-'.Str::lower(Str::random(6));
                $location->update(['building' => $key]);
                // Точки, которые были в другом здании, переходят в это
                Location::whereIn('id', $mateIds)->update(['building' => $key]);
                Location::where('building', $key)->whereNotIn('id', [...$mateIds, $location->id])->update(['building' => null]);
            }
            // Здание, где осталась одна точка, — уже не «одно здание»
            $keys = Location::whereNotNull('building')->select('building')->groupBy('building')->havingRaw('count(*) < 2')->pluck('building');
            if ($keys->isNotEmpty()) {
                Location::whereIn('building', $keys)->update(['building' => null]);
            }
        });
    }

    private function validated(Request $request, ?Location $location = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:4', Rule::unique('locations', 'code')->ignore($location?->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(LocationType::class)],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            // Точки в одном здании с этой (склад и магазин под одной крышей — выдача без водителя)
            'same_building' => ['sometimes', 'array'],
            'same_building.*' => ['integer', 'exists:locations,id'],
        ]);
        if ($data['type'] === 'warehouse' && ! preg_match('/^\d{2}$/', $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Код склада — две цифры (XX в адресе), например 02']);
        }
        if ($data['type'] === 'store' && ! preg_match('/^М\d{2,3}$/u', $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Код магазина — «М» и номер, например М05']);
        }
        if ($location && $location->code !== $data['code'] && $location->cells()->exists()) {
            throw ValidationException::withMessages(['code' => 'Код склада с ячейками менять нельзя — он входит в адреса']);
        }

        return $data;
    }
}
