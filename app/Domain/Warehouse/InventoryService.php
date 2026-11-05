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

    /**
     * Скан ячейки и того, что в ней лежит. $code = null — ячейка пустая.
     * Бирки на колёсах комплекта одинаковые, поэтому каждый скан бирки — одно колесо:
     * колёса одного договора могут лежать в разных ячейках (3 в одной, 1 в другой).
     * Скан сверх количества колёс по договору не засчитывается.
     */
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
            $set = $sets->count() === 1 ? $sets->first() : null;
            if ($set) {
                $already = $inv->scans()->where('qr_code', $qr)->get();
                if ($already->count() >= $set->qty) {
                    $where = $already->countBy('cell_address')->map(fn ($n, $a) => "{$a}: {$n}")->implode(', ');
                    throw new DomainError("Лишний скан: все {$set->qty} кол. комплекта {$qr} уже найдены ({$where})");
                }
            }
        }

        return InventoryScan::create([
            'inventory_id' => $inv->id, 'cell_address' => $cell->address, 'qr_code' => $qr,
            'user_id' => Auth::id(), 'scanned_at' => now(),
        ]);
    }

    /** Отменить последний скан (ошибочно отсканировали колесо). */
    public function undo(Inventory $inv, ?User $user = null): ?InventoryScan
    {
        if ($inv->status !== 'open') {
            throw new DomainError('Инвентаризация завершена');
        }
        $scan = $inv->scans()->when($user, fn ($q) => $q->where('user_id', $user->id))->orderByDesc('id')->first();
        if (! $scan) {
            throw DomainError::notFound('Нет сканов для отмены');
        }
        $scan->delete();

        return $scan;
    }

    /** Сколько колёс комплекта найдено: всего и в ячейке. */
    public function wheelsFound(Inventory $inv, string $qr, string $address): array
    {
        $scans = $inv->scans()->where('qr_code', $qr)->get();

        return ['total' => $scans->count(), 'cell' => $scans->where('cell_address', $address)->count(),
            'qty' => WheelSet::where('qr_code', $qr)->value('qty')];
    }

    /**
     * Отчёт: лишние, недостающие, не на своём месте, разнесённые по ячейкам, недостача колёс,
     * непроверенные ячейки. Считается по колёсам: каждый скан бирки — одно колесо.
     */
    public function report(Inventory $inv): array
    {
        $cells = $this->scopeCells($inv)->get(['id', 'address']);
        $expectedSets = WheelSet::with('cell')->whereIn('current_cell_id', $cells->pluck('id'))
            ->whereIn('status', StorageCell::OCCUPYING_STATUSES)->get();
        $expectedAt = $expectedSets->mapWithKeys(fn ($s) => [$s->qr_code => $s->cell->address]);
        $scans = $inv->scans()->get();
        $scannedCells = $scans->pluck('cell_address')->unique();
        // qr => [ячейка => колёс]
        $found = $scans->whereNotNull('qr_code')->groupBy('qr_code')
            ->map(fn ($g) => $g->countBy('cell_address')->sortDesc()->all());
        $known = WheelSet::whereIn('qr_code', $found->keys()->merge($expectedAt->keys())->unique())->get()->keyBy('qr_code');

        $missing = [];
        $misplaced = [];
        $extra = [];
        $split = [];
        $short = [];
        $over = [];
        foreach ($expectedAt as $code => $address) {
            if (! $found->has($code) && $scannedCells->contains($address)) {
                $missing[] = ['qr_code' => $code, 'expected_address' => $address];
            }
        }
        foreach ($found as $code => $byCell) {
            $wheels = array_sum($byCell);
            $main = array_key_first($byCell); // ячейка, где больше всего колёс
            $set = $known->get($code);
            if ($expectedAt->has($code)) {
                $expected = $expectedAt[$code];
                if (! isset($byCell[$expected])) {
                    $misplaced[] = ['qr_code' => $code, 'expected_address' => $expected, 'found_address' => $main, 'cells' => $byCell];
                } elseif (count($byCell) > 1) {
                    $split[] = ['qr_code' => $code, 'expected_address' => $expected, 'cells' => $byCell];
                }
            } else {
                $extra[] = ['qr_code' => $code, 'found_address' => $main, 'cells' => $byCell, 'wheels' => $wheels,
                    'status' => $set?->status->value, 'known' => (bool) $set];
            }
            if ($set && $wheels < $set->qty) {
                $short[] = ['qr_code' => $code, 'found' => $wheels, 'expected' => $set->qty, 'cells' => $byCell];
            } elseif ($set && $wheels > $set->qty) {
                $over[] = ['qr_code' => $code, 'found' => $wheels, 'expected' => $set->qty, 'cells' => $byCell];
            }
        }

        return [
            'cells_total' => $cells->count(),
            'cells_scanned' => $scannedCells->count(),
            'unscanned_cells' => $cells->pluck('address')->diff($scannedCells)->values()->all(),
            'expected_sets' => $expectedSets->count(),
            'wheels_expected' => (int) $expectedSets->sum('qty'),
            'wheels_found' => $scans->whereNotNull('qr_code')->count(),
            'missing' => $missing,
            'misplaced' => $misplaced,
            'extra' => $extra,
            'split' => $split,
            'short' => $short,
            'over' => $over,
            'by_cell' => $scans->whereNotNull('qr_code')->groupBy('cell_address')->map(fn ($g) => $g->countBy('qr_code')->all())->all(),
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
                // Колёс меньше, чем по договору — расхождение и задание ответственному
                foreach ($report['short'] as $m) {
                    $set = $this->mover->lock(WheelSet::where('qr_code', $m['qr_code'])->value('id'));
                    $this->mover->confirmQty($set, $m['found'], "Инвентаризация №{$inv->id}");
                }
                // Колёса одного договора в разных ячейках — задание собрать в одну
                foreach ($report['split'] as $m) {
                    $where = collect($m['cells'])->map(fn ($n, $a) => "{$a}: {$n} кол.")->implode(', ');
                    Task::create([
                        'type' => TaskType::Discrepancy, 'location_id' => $inv->location_id,
                        'wheel_set_id' => WheelSet::where('qr_code', $m['qr_code'])->value('id'),
                        'due_date' => now()->toDateString(), 'status' => TaskStatus::Open,
                        'title' => "Комплект {$m['qr_code']} разнесён по ячейкам ({$where}) — собрать в {$m['expected_address']}",
                        'payload' => ['inventory_id' => $inv->id, 'cells' => $m['cells']],
                    ]);
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
