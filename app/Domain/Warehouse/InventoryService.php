<?php

namespace App\Domain\Warehouse;

use App\Domain\Storage\ScanResolver;
use App\Domain\Storage\SetMover;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\DomainError;
use App\Models\Inventory;
use App\Models\InventoryScan;
use App\Models\Location;
use App\Models\StorageCell;
use App\Models\StorageRow;
use App\Models\Task;
use App\Models\User;
use App\Models\WheelSet;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Инвентаризация (план, 6.7): сканируем ячейки подряд, система сравнивает с ожидаемым.
 */
final class InventoryService
{
    public function __construct(private readonly SetMover $mover) {}

    public function start(Location $location, ?StorageRow $row = null, ?User $user = null): Inventory
    {
        if (! $location->isWarehouse()) {
            throw DomainError::invalid('Инвентаризация проводится на складе');
        }
        if ($row && $row->location_id !== $location->id) {
            throw DomainError::invalid('Ряд не принадлежит складу');
        }
        if (Inventory::where('location_id', $location->id)->where('status', 'open')
            ->when($row, fn ($q) => $q->where(fn ($q) => $q->whereNull('row_id')->orWhere('row_id', $row->id)))->exists()) {
            throw new DomainError('По этому складу/ряду уже идёт инвентаризация');
        }

        return DB::transaction(function () use ($location, $row, $user) {
            $inv = Inventory::create(['location_id' => $location->id, 'row_id' => $row?->id, 'status' => 'open', 'started_by' => $user?->id]);
            Task::create([
                'type' => TaskType::Inventory, 'location_id' => $location->id, 'due_date' => now()->toDateString(),
                'status' => TaskStatus::Open, 'created_by' => $user?->id,
                'title' => 'Инвентаризация: склад '.$location->code.($row ? ', ряд '.$row->row_no : ''),
                'payload' => ['inventory_id' => $inv->id],
            ]);

            return $inv;
        });
    }

    /** Скан ячейки и того, что в ней лежит. $code = null — ячейка пустая. */
    public function scan(Inventory $inv, string $address, ?string $code): InventoryScan
    {
        if ($inv->status !== 'open') {
            throw new DomainError('Инвентаризация завершена');
        }
        $cell = $this->scopeCells($inv)->where('address', trim($address))->first();
        if (! $cell) {
            throw DomainError::notFound("Ячейка {$address} не входит в инвентаризацию");
        }
        $qr = null;
        if ($code !== null && trim($code) !== '') {
            $sets = app(ScanResolver::class)->sets($code);
            $qr = $sets->count() === 1 ? $sets->first()->qr_code : ScanResolver::clean($code);
        }

        return InventoryScan::create([
            'inventory_id' => $inv->id, 'cell_address' => $cell->address, 'qr_code' => $qr,
            'user_id' => Auth::id(), 'scanned_at' => now(),
        ]);
    }

    /** Отчёт: лишние, недостающие, не на своём месте, непроверенные ячейки. */
    public function report(Inventory $inv): array
    {
        $cells = $this->scopeCells($inv)->get(['id', 'address']);
        $cellIds = $cells->pluck('id');
        $expected = WheelSet::with('cell')->whereIn('current_cell_id', $cellIds)
            ->whereIn('status', StorageCell::OCCUPYING_STATUSES)->get()
            ->groupBy(fn ($s) => $s->cell->address)->map(fn ($g) => $g->pluck('qr_code')->all());
        $scans = $inv->scans()->get();
        $scannedCells = $scans->pluck('cell_address')->unique();
        $found = $scans->whereNotNull('qr_code')->groupBy('cell_address')->map(fn ($g) => $g->pluck('qr_code')->unique()->values()->all());

        $allFound = $found->flatten()->unique();
        $missing = [];
        $misplaced = [];
        $extra = [];
        foreach ($expected as $address => $codes) {
            foreach ($codes as $code) {
                if (! $scannedCells->contains($address)) {
                    continue; // ячейку не проверяли
                }
                if (! $allFound->contains($code)) {
                    $missing[] = ['qr_code' => $code, 'expected_address' => $address];
                }
            }
        }
        foreach ($found as $address => $codes) {
            foreach ($codes as $code) {
                if (in_array($code, $expected[$address] ?? [], true)) {
                    continue;
                }
                $expectedAt = $expected->search(fn ($list) => in_array($code, $list, true));
                if ($expectedAt !== false) {
                    $misplaced[] = ['qr_code' => $code, 'expected_address' => $expectedAt, 'found_address' => $address];
                } else {
                    $set = WheelSet::where('qr_code', $code)->first();
                    $extra[] = ['qr_code' => $code, 'found_address' => $address, 'status' => $set?->status->value, 'known' => (bool) $set];
                }
            }
        }
        $misplacedCodes = array_column($misplaced, 'qr_code');
        $missing = array_values(array_filter($missing, fn ($m) => ! in_array($m['qr_code'], $misplacedCodes, true)));

        return [
            'cells_total' => $cells->count(),
            'cells_scanned' => $scannedCells->count(),
            'unscanned_cells' => $cells->pluck('address')->diff($scannedCells)->values()->all(),
            'expected_sets' => $expected->flatten()->count(),
            'missing' => $missing,
            'misplaced' => $misplaced,
            'extra' => $extra,
        ];
    }

    /**
     * Завершить. При $apply: «не на своём месте» — переносим адрес на фактический,
     * «недостающие» — статус «Утерян/расхождение», «лишние» известные комплекты — размещаем по факту.
     */
    public function finish(Inventory $inv, bool $apply = false): Inventory
    {
        if ($inv->status !== 'open') {
            throw new DomainError('Инвентаризация уже завершена');
        }

        return DB::transaction(function () use ($inv, $apply) {
            $report = $this->report($inv);
            if ($apply) {
                foreach ($report['misplaced'] as $m) {
                    $this->relocate($m['qr_code'], $m['found_address'], "Инвентаризация №{$inv->id}: найден не на своём месте");
                }
                foreach ($report['missing'] as $m) {
                    $set = $this->mover->lock(WheelSet::where('qr_code', $m['qr_code'])->value('id'));
                    $this->mover->transition($set, SetStatus::Lost, 'lost', ['current_cell_id' => null],
                        ['note' => "Инвентаризация №{$inv->id}: не найден в {$m['expected_address']}"]);
                }
                foreach ($report['extra'] as $e) {
                    if ($e['known'] && in_array($e['status'], [SetStatus::Lost->value, SetStatus::Received->value], true)) {
                        $this->relocate($e['qr_code'], $e['found_address'], "Инвентаризация №{$inv->id}: найден");
                    }
                }
            }
            $inv->update(['status' => 'finished', 'finished_at' => now(), 'result' => $report + ['applied' => $apply]]);
            Task::where('type', TaskType::Inventory->value)->where('status', TaskStatus::Open->value)
                ->where('payload->inventory_id', $inv->id)
                ->update(['status' => TaskStatus::Done->value, 'completed_at' => now()]);

            return $inv->fresh();
        });
    }

    private function relocate(string $qr, string $address, string $note): void
    {
        $set = $this->mover->lock(WheelSet::where('qr_code', $qr)->value('id'));
        $cell = StorageCell::where('address', $address)->lockForUpdate()->firstOrFail();
        $action = $set->status === SetStatus::Lost ? 'found' : 'move';
        $to = $set->status === SetStatus::Ordered ? SetStatus::Ordered : SetStatus::Placed;
        $this->mover->transition($set, $to, $action, [
            'current_cell_id' => $cell->id, 'current_location_id' => $cell->location_id,
        ], ['note' => $note]);
    }

    private function scopeCells(Inventory $inv)
    {
        return StorageCell::query()->where('location_id', $inv->location_id)
            ->when($inv->row_id, fn ($q) => $q->where('row_id', $inv->row_id))
            ->orderBy('address');
    }
}
