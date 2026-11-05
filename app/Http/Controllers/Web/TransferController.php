<?php

namespace App\Http\Controllers\Web;

use App\Domain\Support\DomainError;
use App\Domain\Transfer\TransferService;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Location;
use App\Models\Transfer;
use App\Models\TransferLine;
use App\Models\WheelSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransferController extends Controller
{
    use Concerns;

    public function index(Request $request): Response
    {
        $location = $this->currentLocation($request);
        $dir = in_array($request->query('dir'), ['in', 'out'], true) ? $request->query('dir') : null;
        $transfers = Transfer::with(['from', 'to', 'trip'])->withCount('lines')
            ->when($location && $dir === 'in', fn ($q) => $q->where('to_location_id', $location->id))
            ->when($location && $dir === 'out', fn ($q) => $q->where('from_location_id', $location->id))
            ->when($location && ! $dir, fn ($q) => $q->where(fn ($q) => $q->where('from_location_id', $location->id)->orWhere('to_location_id', $location->id)))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            // Незавершённые (ожидают погрузки) — сверху, чтобы к ним было легко вернуться
            ->orderByRaw("status = 'created' DESC")->orderByDesc('created_at')->paginate(30)->withQueryString();

        return Inertia::render('Transfers/Index', [
            'transfers' => $transfers->through(fn ($t) => Present::transfer($t)),
            'location' => Present::location($location),
            'locations' => $this->locations(),
            'status' => $request->query('status'),
            'dir' => $dir,
        ]);
    }

    public function create(Request $request, TransferService $service): Response
    {
        $target = $request->filled('transfer') ? Transfer::with(['from', 'to'])->find($request->query('transfer')) : null;
        $from = $target?->from ?? ($request->filled('from') ? Location::find($request->integer('from')) : $request->user()->location);
        $to = $target?->to ?? ($request->filled('to') ? Location::find($request->integer('to')) : null);
        $candidates = collect();
        if ($from) {
            $candidates = $service->bufferCandidates($from)
                ->merge($service->issueCandidates($from, $to))
                ->merge(WheelSet::with(['contract.vehicle', 'cell', 'location'])
                    ->where('current_location_id', $from->id)->whereIn('status', ['placed', 'received', 'ready'])
                    ->limit(300)->get())
                ->unique('id');
        }

        return Inertia::render('Transfers/Create', [
            'locations' => $this->locations(),
            'from' => Present::location($from),
            'to' => Present::location($to),
            'candidates' => $candidates->map(fn ($s) => Present::set($s))->values()->all(),
            'target' => $target && $target->status->value === 'created' ? Present::transfer($target) : null,
            // Уже созданные и не отгруженные перемещения из этой точки — можно дополнить, а не плодить новые
            'drafts' => $from ? Transfer::with(['from', 'to', 'trip'])->withCount('lines')->where('status', 'created')
                ->where('from_location_id', $from->id)->when($target, fn ($q) => $q->whereKeyNot($target->id))
                ->orderByDesc('created_at')->limit(20)->get()->map(fn ($t) => Present::transfer($t))->all() : [],
        ]);
    }

    public function store(Request $request, TransferService $service): RedirectResponse
    {
        $data = $request->validate([
            'from_location_id' => ['required', 'integer'], 'to_location_id' => ['required', 'integer'],
            'set_ids' => ['required', 'array', 'min:1'], 'set_ids.*' => ['uuid'], 'driver' => ['nullable', 'string', 'max:255'],
        ]);
        $transfer = $service->create(Location::findOrFail($data['from_location_id']), Location::findOrFail($data['to_location_id']),
            $data['set_ids'], $request->user(), $data['driver'] ?? null);

        return redirect()->route('transfers.show', $transfer)
            ->with('success', "Перемещение {$transfer->number} создано и ждёт водителя в «Задания → Перевозки». Бирки сканируются при погрузке.");
    }

    public function addSets(Request $request, Transfer $transfer, TransferService $service): RedirectResponse
    {
        $data = $request->validate(['set_ids' => ['required', 'array', 'min:1'], 'set_ids.*' => ['uuid']]);
        $service->addSets($transfer, $data['set_ids']);

        return redirect()->route('transfers.show', $transfer)->with('success', 'Комплекты добавлены в перемещение');
    }

    public function removeLine(Transfer $transfer, TransferLine $line, TransferService $service): RedirectResponse
    {
        $transfer = $service->removeLine($transfer, $line);

        return $transfer->status->value === 'cancelled'
            ? redirect()->route('transfers.index')->with('success', "Перемещение {$transfer->number} стало пустым и отменено")
            : back()->with('success', 'Комплект убран из перемещения');
    }

    public function show(Transfer $transfer): Response
    {
        $transfer->load(['from', 'to', 'trip', 'lines.wheelSet.contract.client', 'lines.wheelSet.contract.vehicle', 'lines.wheelSet.cell', 'lines.wheelSet.location', 'lines.wheelSet.transfer']);

        return Inertia::render('Transfers/Show', ['transfer' => Present::transfer($transfer, true)]);
    }

    public function scanOut(Request $request, Transfer $transfer, TransferService $service): JsonResponse
    {
        $this->guardTrip($transfer);
        $data = $request->validate(['code' => ['required', 'string'], 'qty' => ['nullable', 'integer', 'min:0', 'max:8']]);
        $line = isset($data['qty']) ? $service->scanOut($transfer, $data['code'], $data['qty'])
            : $service->countOut($service->findLine($transfer, $data['code']));

        return response()->json(['message' => TripController::wheelMessage($line, 'out'), 'line_id' => $line->id, 'complete' => $line->scanned_out]);
    }

    public function ship(Request $request, Transfer $transfer, TransferService $service): RedirectResponse
    {
        $this->guardTrip($transfer);
        $service->ship($transfer, $request->input('driver'));

        return back()->with('success', 'Перемещение отгружено — комплекты в пути');
    }

    public function scanIn(Request $request, Transfer $transfer, TransferService $service): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string'], 'qty' => ['nullable', 'integer', 'min:0', 'max:8']]);
        $line = isset($data['qty']) ? $service->scanIn($transfer, $data['code'], $data['qty'])
            : $service->countIn($service->findLine($transfer, $data['code']));

        return response()->json(['message' => TripController::wheelMessage($line, 'in'), 'line_id' => $line->id, 'complete' => $line->scanned_in]);
    }

    public function complete(Transfer $transfer, TransferService $service): RedirectResponse
    {
        $t = $service->complete($transfer);

        return back()->with($t->status->value === 'received' ? 'success' : 'error',
            $t->status->value === 'received' ? 'Приёмка завершена, всё сошлось' : 'Приёмка завершена с расхождением — ответственному создано задание');
    }

    public function cancel(Transfer $transfer, TransferService $service): RedirectResponse
    {
        $service->cancel($transfer);

        return back()->with('success', 'Перемещение отменено');
    }

    /** Перемещение в ведомости грузится и отгружается только целиком по ведомости. */
    private function guardTrip(Transfer $transfer): void
    {
        if ($transfer->trip_id && $transfer->status->value === 'created') {
            throw new DomainError("Перемещение в ведомости {$transfer->trip->number} — погрузка на экране ведомости");
        }
    }
}
