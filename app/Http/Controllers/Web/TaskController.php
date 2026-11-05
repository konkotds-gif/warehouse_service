<?php

namespace App\Http\Controllers\Web;

use App\Domain\Warehouse\Indicators;
use App\Domain\Warehouse\TaskStatus;
use App\Domain\Warehouse\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    use Concerns;

    public function index(Request $request): Response
    {
        $location = $this->currentLocation($request);
        $status = $request->query('status', 'open');
        $tasks = Task::with(['wheelSet.cell', 'wheelSet.location', 'wheelSet.transfer', 'location', 'assignee'])
            ->when($location, fn ($q) => $q->where(fn ($q) => $q->where('location_id', $location->id)
                // Склад и магазин в одном здании: задания «Выдать клиенту» видны в обеих точках
                ->orWhere(fn ($q) => $q->where('type', TaskType::IssueToClient->value)
                    ->whereIn('location_id', $location->buildingMates()->pluck('id')))))
            ->where('status', $status)
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->orderBy('due_date')->orderBy('id')->paginate(50)->withQueryString();

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks->through(fn ($t) => Present::task($t)),
            'location' => Present::location($location),
            'locations' => $this->locations(),
            'filters' => ['status' => $status, 'type' => $request->query('type')],
        ]);
    }

    /** Счётчики актуальной работы для шапки и меню (фоновое обновление). */
    public function indicators(Request $request, Indicators $indicators): JsonResponse
    {
        return response()->json($indicators->for($request->user()));
    }

    public function done(Request $request, Task $task): RedirectResponse
    {
        $task->update(['status' => TaskStatus::Done, 'completed_at' => now(), 'assignee_id' => $task->assignee_id ?? $request->user()->id]);

        return back()->with('success', 'Задание закрыто');
    }
}
