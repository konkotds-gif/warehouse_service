<?php

namespace App\Http\Controllers\Web;

use App\Domain\Warehouse\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Task;
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
            ->when($location, fn ($q) => $q->where('location_id', $location->id))
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

    public function done(Request $request, Task $task): RedirectResponse
    {
        $task->update(['status' => TaskStatus::Done, 'completed_at' => now(), 'assignee_id' => $task->assignee_id ?? $request->user()->id]);

        return back()->with('success', 'Задание закрыто');
    }
}
