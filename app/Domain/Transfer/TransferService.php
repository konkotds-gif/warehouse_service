<?php

namespace App\Domain\Transfer;

use App\Domain\Integration\OneC\Outbox;
use App\Domain\Notifications\Sms;
use App\Domain\Storage\ScanResolver;
use App\Domain\Storage\SetMover;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\DomainError;
use App\Domain\Support\NumberGenerator;
use App\Domain\Warehouse\TaskStatus;
use App\Domain\Warehouse\TaskType;
use App\Domain\Warehouse\TransferStatus;
use App\Models\Location;
use App\Models\Task;
use App\Models\Transfer;
use App\Models\TransferLine;
use App\Models\User;
use App\Models\WheelSet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Перемещения (план, 6.2, 6.4, 6.6): создание, погрузка по скану, приёмка по скану, расхождения.
 */
final class TransferService
{
    private const SHIPPABLE = [SetStatus::Accepted, SetStatus::Received, SetStatus::Placed, SetStatus::Ordered, SetStatus::Ready];

    public function __construct(
        private readonly SetMover $mover,
        private readonly NumberGenerator $numbers,
        private readonly Outbox $outbox,
        private readonly ScanResolver $resolver,
        private readonly Sms $sms,
    ) {}

    /** Комплекты в буфере магазина, которые ещё не включены в перемещение. */
    public function bufferCandidates(Location $from): Collection
    {
        return $this->availableQuery($from)->where('status', SetStatus::Accepted->value)->get();
    }

    /** Заказанные к выдаче комплекты на складе для магазина. */
    public function issueCandidates(Location $from, ?Location $to = null): Collection
    {
        return $this->availableQuery($from)
            ->where('status', SetStatus::Ordered->value)
            ->when($to, fn ($q) => $q->where('issue_store_id', $to->id))
            ->get();
    }

    private function availableQuery(Location $from)
    {
        return WheelSet::with(['contract.vehicle', 'cell'])
            ->where('current_location_id', $from->id)
            ->whereNotIn('id', $this->openLineSetIds());
    }

    /** Строки, которые ещё «держат» комплект: неотгруженные перемещения и не принятые в пункте назначения. */
    private function openLines()
    {
        return TransferLine::query()->where(function ($q) {
            $q->whereIn('transfer_id', Transfer::query()->select('id')->where('status', TransferStatus::Created->value))
                ->orWhere(fn ($q) => $q->where('scanned_in', false)->whereIn('transfer_id', Transfer::query()->select('id')
                    ->whereIn('status', [TransferStatus::Shipped->value, TransferStatus::Discrepancy->value])));
        });
    }

    private function openLineSetIds()
    {
        return $this->openLines()->select('wheel_set_id');
    }

    /** @param list<string> $setIds */
    public function create(Location $from, Location $to, array $setIds, ?User $user = null, ?string $driver = null, ?string $purpose = null): Transfer
    {
        if ($from->id === $to->id) {
            throw DomainError::invalid('Пункт отправления и назначения совпадают');
        }
        if ($setIds === []) {
            throw DomainError::invalid('Выберите комплекты для перемещения');
        }

        return DB::transaction(function () use ($from, $to, $setIds, $user, $driver, $purpose) {
            $sets = WheelSet::whereIn('id', $setIds)->lockForUpdate()->get();
            if ($sets->count() !== count(array_unique($setIds))) {
                throw DomainError::notFound('Часть комплектов не найдена');
            }
            $busy = $this->openLines()->whereIn('wheel_set_id', $setIds)->pluck('wheel_set_id');
            foreach ($sets as $set) {
                if ($set->current_location_id !== $from->id) {
                    throw new DomainError("Комплект {$set->qr_code} находится не в {$from->code}");
                }
                if (! in_array($set->status, self::SHIPPABLE, true)) {
                    throw new DomainError("Комплект {$set->qr_code} в статусе «{$set->status->label()}» нельзя отправить");
                }
                if ($busy->contains($set->id)) {
                    throw new DomainError("Комплект {$set->qr_code} уже включён в другое перемещение");
                }
            }
            $purpose ??= $sets->every(fn ($s) => $s->status === SetStatus::Ordered && $s->issue_store_id === $to->id) ? 'issue'
                : ($to->isWarehouse() ? 'storage' : 'relocation');

            $transfer = Transfer::create([
                'number' => $this->numbers->transferNumber(),
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'status' => TransferStatus::Created,
                'purpose' => $purpose,
                'driver' => $driver,
                'created_by' => $user?->id,
            ]);
            foreach ($sets as $set) {
                $transfer->lines()->create(['wheel_set_id' => $set->id]);
            }

            return $transfer->load('lines.wheelSet');
        });
    }

    /** Скан при погрузке. */
    public function scanOut(Transfer $transfer, string $code, ?int $qty = null): TransferLine
    {
        return DB::transaction(function () use ($transfer, $code, $qty) {
            $transfer = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== TransferStatus::Created) {
                throw new DomainError('Перемещение уже отгружено или закрыто');
            }
            $line = $this->findLine($transfer, $code);
            $set = $this->mover->lock($line->wheel_set_id);
            $this->mover->confirmQty($set, $qty, "Погрузка {$transfer->number}");
            $line->update(['scanned_out' => true, 'qty_out' => $qty ?? $set->qty]);

            return $line->fresh('wheelSet');
        });
    }

    /** Отгрузка: отсканированные комплекты → В_ПУТИ, неотсканированные исключаются из перемещения. */
    public function ship(Transfer $transfer, ?string $driver = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $driver) {
            $transfer = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== TransferStatus::Created) {
                throw new DomainError('Перемещение уже отгружено или закрыто');
            }
            $lines = $transfer->lines()->get();
            $scanned = $lines->where('scanned_out', true);
            if ($scanned->isEmpty()) {
                throw new DomainError('Не отсканировано ни одного комплекта');
            }
            foreach ($lines->where('scanned_out', false) as $line) {
                $line->delete();
            }
            foreach ($scanned as $line) {
                $set = $this->mover->lock($line->wheel_set_id);
                $this->mover->transition($set, SetStatus::InTransit, 'ship', [
                    'current_cell_id' => null,
                    'current_location_id' => null,
                    'transfer_id' => $transfer->id,
                ], ['qty' => $line->qty_out, 'transfer_id' => $transfer->id, 'note' => "{$transfer->number}: в {$transfer->to->code}"]);
                $this->mover->closeTasks($set, TaskType::Pick);
            }
            $transfer->update([
                'status' => TransferStatus::Shipped,
                'shipped_at' => now(),
                'driver' => $driver ?? $transfer->driver,
            ]);

            return $transfer->fresh('lines.wheelSet');
        });
    }

    /** Скан при приёмке: комплект сразу получает статус в пункте назначения. */
    public function scanIn(Transfer $transfer, string $code, ?int $qty = null): TransferLine
    {
        return DB::transaction(function () use ($transfer, $code, $qty) {
            $transfer = Transfer::with('to')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if (! in_array($transfer->status, [TransferStatus::Shipped, TransferStatus::Discrepancy], true)) {
                throw new DomainError('Перемещение не в пути');
            }
            $line = $this->findLine($transfer, $code);
            if ($line->scanned_in) {
                throw new DomainError('Комплект уже принят');
            }
            $set = $this->mover->lock($line->wheel_set_id);
            $this->mover->confirmQty($set, $qty, "Приёмка {$transfer->number}");
            $line->update(['scanned_in' => true, 'qty_in' => $qty ?? $set->qty]);

            $to = $transfer->to;
            $readyForIssue = $to->isStore() && $set->issue_store_id === $to->id;
            $status = match (true) {
                $to->isWarehouse() => SetStatus::Received,
                $readyForIssue => SetStatus::Ready,
                default => SetStatus::Accepted,
            };
            $this->mover->transition($set, $status, $readyForIssue ? 'ready' : 'receive', [
                'current_location_id' => $to->id,
                'current_cell_id' => null,
                'transfer_id' => null,
            ], ['qty' => $qty ?? $set->qty, 'transfer_id' => $transfer->id, 'note' => $transfer->number]);

            if ($status === SetStatus::Received) {
                Task::create([
                    'type' => TaskType::Place, 'wheel_set_id' => $set->id, 'location_id' => $to->id,
                    'due_date' => now()->toDateString(), 'status' => TaskStatus::Open,
                    'title' => "Разместить {$set->qr_code} ({$set->tire_size}, {$set->qty} шт)",
                ]);
            }
            if ($readyForIssue) {
                $this->sms->setReady($set);
            }

            // Всё принято — перемещение закрывается автоматически
            if (! $transfer->lines()->where('scanned_in', false)->exists()) {
                $wasShipped = $transfer->status === TransferStatus::Shipped;
                $transfer->update(['status' => TransferStatus::Received, 'received_at' => now()]);
                if ($wasShipped) {
                    $this->outbox->record('transfer.completed', $this->outbox->transferPayload($transfer->fresh()));
                }
            }

            return $line->fresh('wheelSet');
        });
    }

    /** Завершить приёмку: всё сошлось → «Принято», иначе «Расхождение» + задание ответственному. */
    public function complete(Transfer $transfer): Transfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== TransferStatus::Shipped) {
                throw new DomainError('Перемещение не в пути');
            }
            $missing = $transfer->lines()->where('scanned_in', false)->with('wheelSet')->get();
            if ($missing->isEmpty()) {
                $transfer->update(['status' => TransferStatus::Received, 'received_at' => now()]);
            } else {
                $transfer->update(['status' => TransferStatus::Discrepancy, 'received_at' => now()]);
                $codes = $missing->map(fn ($l) => $l->wheelSet->qr_code)->implode(', ');
                Task::create([
                    'type' => TaskType::Discrepancy, 'location_id' => $transfer->to_location_id,
                    'due_date' => now()->toDateString(), 'status' => TaskStatus::Open,
                    'title' => "Расхождение по перемещению {$transfer->number}: не приняты {$codes}",
                    'payload' => ['transfer_id' => $transfer->id, 'missing' => $missing->pluck('wheelSet.qr_code')->all()],
                ]);
            }
            $this->outbox->record('transfer.completed', $this->outbox->transferPayload($transfer->fresh()));

            return $transfer->fresh('lines.wheelSet');
        });
    }

    public function cancel(Transfer $transfer): Transfer
    {
        if ($transfer->status !== TransferStatus::Created) {
            throw new DomainError('Отменить можно только неотгруженное перемещение');
        }
        $transfer->update(['status' => TransferStatus::Cancelled]);

        return $transfer;
    }

    private function findLine(Transfer $transfer, string $code): TransferLine
    {
        $setIds = $this->resolver->sets($code)->pluck('id');
        if ($setIds->isEmpty()) {
            throw DomainError::notFound("Бирка «{$code}» не найдена");
        }
        $lines = $transfer->lines()->whereIn('wheel_set_id', $setIds)->get();
        if ($lines->isEmpty()) {
            throw new DomainError("Комплект «{$code}» не входит в перемещение {$transfer->number}");
        }
        if ($lines->count() > 1) {
            $pending = $lines->first(fn ($l) => ! $l->scanned_out || ($transfer->status !== TransferStatus::Created && ! $l->scanned_in));

            return $pending ?? $lines->first();
        }

        return $lines->first();
    }
}
