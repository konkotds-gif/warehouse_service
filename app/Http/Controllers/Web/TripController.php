<?php

namespace App\Http\Controllers\Web;

use App\Domain\Transfer\TripService;
use App\Domain\Warehouse\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Transfer;
use App\Models\TransferLine;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Пул перевозок и ведомости заданий на перевозку (раздел «Задания» → «Перевозки»). */
class TripController extends Controller
{
    use Concerns;

    private const TRANSFER_RELATIONS = ['from', 'to', 'lines.wheelSet.contract.client', 'lines.wheelSet.contract.vehicle',
        'lines.wheelSet.cell', 'lines.wheelSet.location'];

    public function index(Request $request, TripService $service): Response
    {
        $location = $this->currentLocation($request);
        $pool = $service->pool()->with(self::TRANSFER_RELATIONS)
            ->when($location, fn ($q) => $q->where(fn ($q) => $q->where('from_location_id', $location->id)->orWhere('to_location_id', $location->id)))
            ->orderBy('created_at')->get();
        $trips = Trip::with(['transfers.from', 'transfers.to'])->withCount('transfers')
            ->when($location, fn ($q) => $q->whereHas('transfers', fn ($q) => $q->where('from_location_id', $location->id)->orWhere('to_location_id', $location->id)))
            ->where(fn ($q) => $q->whereIn('status', [TripStatus::Loading->value, TripStatus::Shipped->value])
                ->orWhere('updated_at', '>=', now()->subDays(7)))
            ->orderByRaw("FIELD(status, 'loading', 'shipped', 'done', 'cancelled')")->orderByDesc('created_at')->limit(50)->get();

        return Inertia::render('Tasks/Transport', [
            'pool' => $pool->map(fn ($t) => Present::transfer($t, true))->all(),
            'trips' => $trips->map(fn ($t) => Present::trip($t))->all(),
            'location' => Present::location($location),
            'locations' => $this->locations(),
        ]);
    }

    public function store(Request $request, TripService $service): RedirectResponse
    {
        $data = $request->validate([
            'transfer_ids' => ['required', 'array', 'min:1'], 'transfer_ids.*' => ['uuid'],
            'driver' => ['nullable', 'string', 'max:255'], 'vehicle' => ['nullable', 'string', 'max:255'],
        ]);
        $trip = $service->create($data['transfer_ids'], $data['driver'] ?? null, $data['vehicle'] ?? null, $request->user());

        return redirect()->route('trips.show', $trip)->with('success', "Ведомость {$trip->number} сформирована. Сканируйте бирки при погрузке.");
    }

    public function show(Request $request, Trip $trip, TripService $service): Response
    {
        $trip->load(array_merge(['author'], array_map(fn ($r) => "transfers.{$r}", self::TRANSFER_RELATIONS)));
        $pool = $trip->status === TripStatus::Loading
            ? $service->pool()->with(['from', 'to'])->withCount('lines')->orderBy('created_at')->get()->map(fn ($t) => Present::transfer($t))->all()
            : [];

        return Inertia::render('Trips/Show', ['trip' => Present::trip($trip, true), 'pool' => $pool]);
    }

    public function update(Request $request, Trip $trip, TripService $service): RedirectResponse
    {
        $data = $request->validate(['driver' => ['nullable', 'string', 'max:255'], 'vehicle' => ['nullable', 'string', 'max:255']]);
        $service->update($trip, $data['driver'] ?? null, $data['vehicle'] ?? null);

        return back()->with('success', 'Водитель и машина сохранены');
    }

    public function addTransfers(Request $request, Trip $trip, TripService $service): RedirectResponse
    {
        $data = $request->validate(['transfer_ids' => ['required', 'array', 'min:1'], 'transfer_ids.*' => ['uuid']]);
        $service->addTransfers($trip, $data['transfer_ids']);

        return back()->with('success', 'Перемещения добавлены в ведомость');
    }

    public function detach(Trip $trip, Transfer $transfer, TripService $service): RedirectResponse
    {
        $service->detach($trip, $transfer);

        return back()->with('success', "Перемещение {$transfer->number} возвращено в пул");
    }

    public function scanOut(Request $request, Trip $trip, TripService $service): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string'], 'qty' => ['nullable', 'integer', 'min:0', 'max:8']]);
        $line = $service->scanOut($trip, $data['code'], $data['qty'] ?? null);

        return response()->json(['message' => "Погружен {$line->wheelSet->qr_code} ({$line->transfer->number})", 'line_id' => $line->id]);
    }

    public function unscan(Trip $trip, TransferLine $line, TripService $service): RedirectResponse
    {
        $service->unscan($trip, $line);

        return back()->with('success', 'Отметка погрузки снята');
    }

    public function ship(Trip $trip, TripService $service): RedirectResponse
    {
        $result = $service->ship($trip);
        $msg = "Ведомость {$trip->number} отгружена: перемещений {$result['shipped']->count()}";
        if ($result['returned']->isNotEmpty()) {
            $msg .= '. Не погруженное вернулось в пул: '.$result['returned']->pluck('number')->implode(', ');
        }

        return back()->with('success', $msg);
    }

    public function scanIn(Request $request, Trip $trip, TripService $service): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string'], 'qty' => ['nullable', 'integer', 'min:0', 'max:8']]);
        $line = $service->scanIn($trip, $data['code'], $data['qty'] ?? null);
        $set = $line->wheelSet->fresh();

        return response()->json(['message' => "Принят {$set->qr_code}: {$set->status->label()} ({$line->transfer->number})", 'line_id' => $line->id]);
    }

    public function complete(Trip $trip, TripService $service): RedirectResponse
    {
        $trip = $service->complete($trip);
        $bad = $trip->transfers->where('status.value', 'discrepancy')->pluck('number');

        return back()->with($bad->isEmpty() ? 'success' : 'error', $bad->isEmpty()
            ? 'Приёмка по ведомости завершена, всё сошлось'
            : 'Приёмка завершена с расхождением ('.$bad->implode(', ').') — ответственному создано задание');
    }

    public function cancel(Trip $trip, TripService $service): RedirectResponse
    {
        $service->cancel($trip);

        return redirect()->route('trips.index')->with('success', "Ведомость {$trip->number} отменена, перемещения вернулись в пул");
    }

    /** Печатная ведомость / накладная водителю. */
    public function print(Trip $trip)
    {
        $trip->load(array_map(fn ($r) => "transfers.{$r}", self::TRANSFER_RELATIONS));

        return response()->view('print.trip', [
            'title' => "Ведомость на перевозку № {$trip->number}",
            'trip' => $trip,
            'transfers' => $trip->transfers->sortBy('number')->values(),
            'tz' => config('app.display_timezone'),
        ]);
    }

    /** Накладная водителю по одному перемещению (без ведомости). */
    public function printTransfer(Transfer $transfer)
    {
        $transfer->load(array_merge(self::TRANSFER_RELATIONS, ['trip']));

        return response()->view('print.trip', [
            'title' => "Накладная на перемещение № {$transfer->number}",
            'trip' => $transfer->trip,
            'transfers' => collect([$transfer]),
            'tz' => config('app.display_timezone'),
        ]);
    }
}
