<?php

namespace App\Http\Controllers\Web;

use App\Domain\Storage\ContractStatus;
use App\Domain\Storage\SetStatus;
use App\Domain\Warehouse\Indicators;
use App\Domain\Warehouse\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\OutboxEvent;
use App\Models\StorageContract;
use App\Models\Task;
use App\Models\WheelSet;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use Concerns;

    public function __invoke(Request $request, Indicators $indicators): Response
    {
        $location = $this->currentLocation($request);
        $byStatus = WheelSet::query()
            ->when($location, fn ($q) => $q->where(fn ($q) => $q->where('current_location_id', $location->id)
                ->orWhere('issue_store_id', $location->id)))
            ->whereIn('status', SetStatus::activeValues())
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $tasks = Task::with(['wheelSet', 'location'])->where('status', TaskStatus::Open->value)
            ->when($location, fn ($q) => $q->where('location_id', $location->id))
            ->orderBy('due_date')->limit(8)->get();

        $readyToday = WheelSet::with(['contract.client', 'contract.vehicle', 'location'])
            ->where('status', SetStatus::Ready->value)
            ->when($location, fn ($q) => $q->where('current_location_id', $location->id))
            ->orderBy('issue_due_date')->limit(10)->get();

        return Inertia::render('Dashboard', [
            'location' => Present::location($location),
            'locations' => $this->locations(),
            // Для выбранной руководителем точки — её индикаторы; иначе совпадают с общими из шапки
            'attention' => $location?->id !== $request->user()->location_id ? $indicators->for($request->user(), $location) : null,
            'counts' => $byStatus,
            'overdue' => StorageContract::where('status', ContractStatus::Overdue->value)
                ->when($location, fn ($q) => $q->where('store_id', $location->id))->count(),
            'tasks' => $tasks->map(fn ($t) => Present::task($t))->all(),
            'tasks_total' => Task::where('status', TaskStatus::Open->value)->when($location, fn ($q) => $q->where('location_id', $location->id))->count(),
            'ready' => $readyToday->map(fn ($s) => Present::set($s) + ['client' => $s->contract->client?->full_name])->all(),
            'integration' => $request->user()->isAdmin() ? [
                'pending' => OutboxEvent::whereNull('delivered_at')->count(),
                'stale' => OutboxEvent::whereNull('delivered_at')->where('created_at', '<', now()->subMinutes((int) config('storage.onec.ack_alert_minutes', 30)))->count(),
            ] : null,
        ]);
    }
}
