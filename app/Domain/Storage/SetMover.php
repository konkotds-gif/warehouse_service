<?php

namespace App\Domain\Storage;

use App\Domain\Support\DomainError;
use App\Domain\Warehouse\TaskStatus;
use App\Domain\Warehouse\TaskType;
use App\Models\Movement;
use App\Models\Task;
use App\Models\WheelSet;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Единственная точка изменения статуса и адреса комплекта.
 * В одной транзакции: блокировка строки, проверка перехода, сохранение, запись в журнал движений.
 */
final class SetMover
{
    /** Заблокировать комплект на время транзакции и вернуть свежую копию. */
    public function lock(WheelSet|string $set): WheelSet
    {
        $this->assertTransaction();
        $id = $set instanceof WheelSet ? $set->id : $set;

        return WheelSet::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $changes  поля комплекта (current_cell_id, current_location_id, transfer_id…)
     * @param  array{note?: string|null, qty?: int|null, transfer_id?: string|null}  $log
     */
    public function transition(WheelSet $set, SetStatus $to, string $action, array $changes = [], array $log = []): WheelSet
    {
        $this->assertTransaction();
        $from = $set->status;
        if ($from !== $to && ! $from->canTransitionTo($to)) {
            throw new DomainError("Недопустимое действие для комплекта {$set->qr_code}: «{$from->label()}» → «{$to->label()}»");
        }
        if ($from === $to && ! in_array($action, ['move', 'edit', 'found'], true)) {
            throw new DomainError("Комплект {$set->qr_code} уже в статусе «{$to->label()}»");
        }

        $fromAddress = $set->currentAddress();
        $set->fill($changes);
        $set->status = $to;
        $set->save();
        $set->unsetRelation('cell')->unsetRelation('location')->unsetRelation('transfer');

        $this->log($set, $action, $from, $to, $fromAddress, $log);

        return $set;
    }

    /** Запись в журнал без смены статуса (расхождения, перепечатка, правка). */
    public function log(WheelSet $set, string $action, ?SetStatus $from, ?SetStatus $to, ?string $fromAddress, array $log = []): Movement
    {
        return Movement::create([
            'wheel_set_id' => $set->id,
            'at' => now(),
            'user_id' => Auth::id(),
            'action' => $action,
            'from_address' => $fromAddress,
            'to_address' => $set->currentAddress(),
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'transfer_id' => $log['transfer_id'] ?? $set->transfer_id,
            'qty' => $log['qty'] ?? null,
            'device' => self::device(),
            'note' => isset($log['note']) ? mb_substr((string) $log['note'], 0, 255) : null,
        ]);
    }

    /**
     * Подтверждение количества колёс сотрудником (QR на всех бирках одинаковый).
     * Расхождение пишется в журнал и уходит ответственному как задание.
     */
    public function confirmQty(WheelSet $set, ?int $actual, string $context): bool
    {
        if ($actual === null || $actual === $set->qty) {
            return true;
        }
        $note = "{$context}: по договору {$set->qty} кол., фактически {$actual}";
        $this->log($set, 'qty_mismatch', $set->status, $set->status, $set->currentAddress(), ['qty' => $actual, 'note' => $note]);

        $locationId = $set->current_location_id ?? $set->contract->store_id;
        Task::create([
            'type' => TaskType::Discrepancy,
            'wheel_set_id' => $set->id,
            'location_id' => $locationId,
            'due_date' => now()->toDateString(),
            'status' => TaskStatus::Open,
            'title' => "Расхождение по комплекту {$set->qr_code}: {$note}",
            'payload' => ['expected' => $set->qty, 'actual' => $actual, 'context' => $context],
            'created_by' => Auth::id(),
        ]);

        return false;
    }

    /** Закрыть открытые задания по комплекту данного типа. */
    public function closeTasks(WheelSet $set, TaskType ...$types): void
    {
        Task::where('wheel_set_id', $set->id)
            ->whereIn('type', array_map(fn (TaskType $t) => $t->value, $types))
            ->where('status', TaskStatus::Open->value)
            ->update(['status' => TaskStatus::Done->value, 'completed_at' => now()]);
    }

    public static function device(): ?string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return 'console';
        }
        $request = request();
        $device = $request->header('X-Device') ?: $request->userAgent();

        return $device ? mb_substr($device, 0, 120) : null;
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Изменение комплекта допускается только внутри транзакции');
        }
    }
}
