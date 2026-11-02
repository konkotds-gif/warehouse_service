<?php

namespace App\Http\Controllers\Web;

use App\Domain\Storage\ContractStatus;
use App\Domain\Storage\SetStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Location;
use App\Models\Movement;
use App\Models\StorageCell;
use App\Models\StorageContract;
use App\Models\WheelSet;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Отчёты (этап 4): заполненность складов, комплекты по статусам, оборот по магазинам, просрочки. */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $from = Carbon::parse($request->query('from', now()->subDays(30)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', now()->toDateString()))->endOfDay();

        $occupied = WheelSet::whereIn('status', StorageCell::OCCUPYING_STATUSES)->whereNotNull('current_cell_id')
            ->selectRaw('current_location_id, count(*) c')->groupBy('current_location_id')->pluck('c', 'current_location_id');
        $warehouses = Location::warehouses()->withSum('cells', 'capacity')->withCount('cells')->orderBy('code')->get()
            ->map(fn ($l) => ['code' => $l->code, 'name' => $l->name, 'capacity' => (int) $l->cells_sum_capacity,
                'occupied' => (int) ($occupied[$l->id] ?? 0), 'cells' => $l->cells_count]);

        $byStatus = WheelSet::selectRaw('status, count(*) c')->whereIn('status', SetStatus::activeValues())
            ->groupBy('status')->pluck('c', 'status');

        $accepted = StorageContract::whereBetween('accepted_at', [$from, $to])
            ->selectRaw('store_id, count(*) c, sum(amount) s')->groupBy('store_id')->get()->keyBy('store_id');
        $issued = Movement::where('action', 'issue')->whereBetween('at', [$from, $to])
            ->join('wheel_sets', 'wheel_sets.id', '=', 'movements.wheel_set_id')
            ->selectRaw('wheel_sets.issue_store_id as store_id, count(*) c')->groupBy('wheel_sets.issue_store_id')->pluck('c', 'store_id');
        $stores = Location::stores()->orderBy('code')->get()->map(fn ($l) => [
            'code' => $l->code, 'name' => $l->name,
            'accepted' => (int) ($accepted[$l->id]->c ?? 0), 'amount' => (float) ($accepted[$l->id]->s ?? 0),
            'issued' => (int) ($issued[$l->id] ?? 0),
        ]);

        $overdue = StorageContract::with(['client', 'vehicle', 'store', 'sets.cell', 'sets.location', 'sets.transfer'])
            ->where('status', ContractStatus::Overdue->value)->orderBy('storage_until')->limit(100)->get()
            ->map(fn ($c) => Present::contract($c));

        return Inertia::render('Reports/Index', [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'warehouses' => $warehouses,
            'byStatus' => $byStatus,
            'stores' => $stores,
            'overdue' => $overdue,
        ]);
    }
}
