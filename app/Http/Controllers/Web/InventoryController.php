<?php

namespace App\Http\Controllers\Web;

use App\Domain\Warehouse\InventoryService;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\StorageRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Inventory/Index', [
            'inventories' => Inventory::with(['location', 'row'])->orderByDesc('id')->limit(50)->get()->map(fn ($i) => [
                'id' => $i->id, 'location' => $i->location->label(), 'row' => $i->row?->row_no, 'status' => $i->status,
                'created_at' => $i->created_at->toIso8601String(), 'finished_at' => $i->finished_at?->toIso8601String(),
                'summary' => $i->result ? ['missing' => count($i->result['missing']), 'misplaced' => count($i->result['misplaced']),
                    'extra' => count($i->result['extra'])] : null,
            ]),
            'warehouses' => Location::active()->warehouses()->with('rows')->orderBy('code')->get()->map(fn ($l) => Present::location($l) + [
                'rows' => $l->rows->map(fn ($r) => ['id' => $r->id, 'row_no' => $r->row_no])->all(),
            ]),
        ]);
    }

    public function store(Request $request, InventoryService $service): RedirectResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer'], 'row_id' => ['nullable', 'integer']]);
        $inv = $service->start(Location::findOrFail($data['location_id']),
            ! empty($data['row_id']) ? StorageRow::findOrFail($data['row_id']) : null, $request->user());

        return redirect()->route('inventories.show', $inv);
    }

    public function show(Inventory $inventory, InventoryService $service): Response
    {
        $inventory->load(['location', 'row']);
        $report = $inventory->status === 'open' ? $service->report($inventory) : $inventory->result;
        // Результаты, сохранённые до поштучного счёта, — без новых разделов
        $report += ['split' => [], 'short' => [], 'over' => [], 'by_cell' => [], 'wheels_expected' => null, 'wheels_found' => null];

        return Inertia::render('Inventory/Show', [
            'inventory' => ['id' => $inventory->id, 'status' => $inventory->status, 'location' => $inventory->location->label(),
                'row' => $inventory->row?->row_no, 'result' => $inventory->result],
            'report' => $report,
            'scans' => $inventory->scans()->orderByDesc('id')->limit(30)->get(['cell_address', 'qr_code', 'scanned_at']),
        ]);
    }

    public function scan(Request $request, Inventory $inventory, InventoryService $service): JsonResponse
    {
        $data = $request->validate(['address' => ['required', 'string'], 'code' => ['nullable', 'string']]);
        $scan = $service->scan($inventory, $data['address'], $data['code'] ?? null);

        if (! $scan->qr_code) {
            return response()->json(['message' => "{$scan->cell_address}: пусто"]);
        }
        $n = $service->wheelsFound($inventory, $scan->qr_code, $scan->cell_address);
        $message = $n['qty']
            ? "{$scan->cell_address}: {$scan->qr_code} — колесо {$n['total']} из {$n['qty']}".($n['cell'] !== $n['total'] ? " (в этой ячейке {$n['cell']})" : '')
            : "{$scan->cell_address}: {$scan->qr_code} — бирка не найдена в базе";

        return response()->json(['message' => $message, 'complete' => $n['qty'] && $n['total'] >= $n['qty']]);
    }

    public function undo(Request $request, Inventory $inventory, InventoryService $service): JsonResponse
    {
        $scan = $service->undo($inventory, $request->user());

        return response()->json(['message' => 'Отменён скан: '.$scan->cell_address.($scan->qr_code ? " · {$scan->qr_code}" : ' · пусто')]);
    }

    public function finish(Request $request, Inventory $inventory, InventoryService $service): RedirectResponse
    {
        $service->finish($inventory, $request->boolean('apply'));

        return back()->with('success', 'Инвентаризация завершена');
    }
}
