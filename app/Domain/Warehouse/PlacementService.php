<?php

namespace App\Domain\Warehouse;

use App\Domain\Storage\SetMover;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\DomainError;
use App\Models\StorageCell;
use App\Models\WheelSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Размещение по скану (план, 6.3): система предлагает ячейку, кладовщик сканирует QR бирки и QR ячейки.
 */
final class PlacementService
{
    public function __construct(private readonly SetMover $mover) {}

    /** Свободные ячейки склада, ближние к входу — первыми (по возрастанию адреса). */
    public function freeCells(int $locationId): Builder
    {
        $occupying = "'".implode("','", StorageCell::OCCUPYING_STATUSES)."'";

        return StorageCell::query()
            ->where('location_id', $locationId)
            ->where('is_blocked', false)
            ->whereRaw("(select count(*) from wheel_sets ws where ws.current_cell_id = storage_cells.id and ws.status in ({$occupying})) < storage_cells.capacity")
            ->orderBy('address');
    }

    /**
     * Предложить ячейку. Для комплектов, которые скоро заберут (срок хранения ≤ 30 дней
     * или уже заказан), — ячейку с конца склада, ближе к зоне отгрузки.
     */
    public function suggest(WheelSet $set): ?StorageCell
    {
        $locationId = $set->current_location_id;
        if (! $locationId) {
            return null;
        }
        $soon = $set->status === SetStatus::Ordered
            || ($set->contract?->storage_until && $set->contract->storage_until->lte(now()->addDays(30)));

        $q = $this->freeCells($locationId);
        if ($soon) {
            $q->reorder('address', 'desc');
        }

        return $q->first();
    }

    public function place(WheelSet $set, string $address, ?string $suggested = null): WheelSet
    {
        $address = trim($address);
        if (! StorageCell::isValidAddress($address)) {
            throw DomainError::invalid("Адрес «{$address}» не соответствует формату XX_YY_ZZ", 'address');
        }

        return DB::transaction(function () use ($set, $address, $suggested) {
            $set = $this->mover->lock($set);
            $cell = StorageCell::where('address', $address)->lockForUpdate()->first();
            if (! $cell) {
                throw DomainError::notFound("Ячейка {$address} не найдена");
            }
            if ($cell->is_blocked) {
                throw new DomainError("Ячейка {$address} заблокирована");
            }
            if (! in_array($set->status, [SetStatus::Received, SetStatus::Placed, SetStatus::Ordered, SetStatus::Lost], true)) {
                throw new DomainError("Комплект {$set->qr_code} в статусе «{$set->status->label()}» нельзя разместить");
            }
            $setLocation = $set->current_location_id ?? $set->cell?->location_id;
            if ($set->status !== SetStatus::Lost && $setLocation !== $cell->location_id) {
                throw new DomainError("Ячейка {$address} на другом складе — сначала оформите перемещение");
            }
            if ($set->status === SetStatus::Ordered && $set->current_cell_id === null) {
                throw new DomainError('Комплект заказан к выдаче и не на складе');
            }
            if ($set->current_cell_id === $cell->id) {
                throw new DomainError("Комплект уже лежит в ячейке {$address}");
            }

            $occupied = WheelSet::where('current_cell_id', $cell->id)
                ->whereIn('status', StorageCell::OCCUPYING_STATUSES)
                ->where('id', '!=', $set->id)
                ->count();
            if ($occupied >= $cell->capacity) {
                throw new DomainError("Ячейка {$address} занята");
            }

            $action = match ($set->status) {
                SetStatus::Placed, SetStatus::Ordered => 'move',
                SetStatus::Lost => 'found',
                default => 'place',
            };
            $note = ($suggested && $suggested !== $address) ? "Положили не в предложенную ячейку (предлагалась {$suggested})" : null;
            $to = $set->status === SetStatus::Ordered ? SetStatus::Ordered : SetStatus::Placed;

            $this->mover->transition($set, $to, $action, [
                'current_cell_id' => $cell->id,
                'current_location_id' => $cell->location_id,
            ], ['note' => $note]);
            $this->mover->closeTasks($set, TaskType::Place);

            return $set->fresh(['cell', 'contract']);
        });
    }
}
