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
use App\Domain\Warehouse\TripStatus;
use App\Models\Location;
use App\Models\Task;
use App\Models\Transfer;
use App\Models\TransferLine;
use App\Models\Trip;
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

    /** Сколько комплектов в статусе $status лежит в точке (или во всей сети) и ещё не включено в перемещение. */
    public function availableCount(?Location $from, SetStatus $status): int
    {
        return WheelSet::query()
            ->when($from, fn ($q) => $q->where('current_location_id', $from->id))
            ->where('status', $status->value)
            ->whereNotIn('id', $this->openLineSetIds())
            ->count();
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
            $sets = $this->lockShippable($from, $setIds);
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

    /**
     * Добавить комплекты (в т.ч. других договоров) в ещё не отгруженное перемещение.
     *
     * @param  list<string>  $setIds
     */
    public function addSets(Transfer $transfer, array $setIds): Transfer
    {
        if ($setIds === []) {
            throw DomainError::invalid('Выберите комплекты для перемещения');
        }

        return DB::transaction(function () use ($transfer, $setIds) {
            $transfer = $this->lockEditable($transfer);
            $sets = $this->lockShippable($transfer->from, $setIds);
            foreach ($sets as $set) {
                $transfer->lines()->create(['wheel_set_id' => $set->id]);
            }

            return $transfer->fresh('lines.wheelSet');
        });
    }

    /** Убрать комплект из неотгруженного перемещения (пустое перемещение отменяется). */
    public function removeLine(Transfer $transfer, TransferLine $line): Transfer
    {
        return DB::transaction(function () use ($transfer, $line) {
            $transfer = $this->lockEditable($transfer);
            if ($line->transfer_id !== $transfer->id) {
                throw DomainError::notFound('Строка не относится к перемещению');
            }
            $line->delete();
            if (! $transfer->lines()->exists()) {
                $transfer->update(['status' => TransferStatus::Cancelled, 'trip_id' => null]);
            }

            return $transfer->fresh('lines.wheelSet');
        });
    }

    private function lockEditable(Transfer $transfer): Transfer
    {
        $transfer = Transfer::with(['from', 'trip'])->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        if ($transfer->status !== TransferStatus::Created) {
            throw new DomainError('Перемещение уже отгружено или закрыто');
        }

        return $transfer;
    }

    /** @param list<string> $setIds */
    private function lockShippable(Location $from, array $setIds): Collection
    {
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

        return $sets;
    }

    /** Скан при погрузке. */
    public function scanOut(Transfer $transfer, string $code, ?int $qty = null): TransferLine
    {
        return DB::transaction(fn () => $this->scanOutLine($this->findLine($transfer, $code), $qty));
    }

    /** Отметить строку погруженной (поиск строки по бирке — на вызывающей стороне). */
    public function scanOutLine(TransferLine $line, ?int $qty = null): TransferLine
    {
        return DB::transaction(function () use ($line, $qty) {
            $transfer = Transfer::whereKey($line->transfer_id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== TransferStatus::Created) {
                throw new DomainError('Перемещение уже отгружено или закрыто');
            }
            $set = $this->mover->lock($line->wheel_set_id);
            $this->mover->confirmQty($set, $qty, "Погрузка {$transfer->number}");
            $line->update(['scanned_out' => true, 'qty_out' => $qty ?? $set->qty, 'wheels_out' => $qty ?? $set->qty]);

            return $line->fresh(['wheelSet', 'transfer']);
        });
    }

    /**
     * Поштучный скан колеса при погрузке. Бирки на всех колёсах комплекта одинаковые (QR договора),
     * поэтому каждый скан — одно колесо; комплект погружен, когда набралось колёс по договору.
     * Лишний скан (колесо отсканировали дважды) не засчитывается.
     */
    public function countOut(TransferLine $line): TransferLine
    {
        return DB::transaction(function () use ($line) {
            $transfer = Transfer::whereKey($line->transfer_id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== TransferStatus::Created) {
                throw new DomainError('Перемещение уже отгружено или закрыто');
            }
            $line = TransferLine::with('wheelSet')->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $expected = $line->wheelSet->qty;
            if ($line->scanned_out || $line->wheels_out >= $expected) {
                throw new DomainError("Лишний скан: комплект {$line->wheelSet->qr_code} уже погружен полностью ({$line->wheels_out} из {$expected} кол.). "
                    .'Если колёс действительно больше — укажите фактическое количество кнопкой и отсканируйте бирку.');
            }
            $wheels = $line->wheels_out + 1;
            if ($wheels >= $expected) {
                return $this->scanOutLine($line);
            }
            $line->update(['wheels_out' => $wheels]);

            return $line->fresh(['wheelSet', 'transfer']);
        });
    }

    /** Отменить скан одного колеса (или всего комплекта) при погрузке. */
    public function uncountOut(TransferLine $line, bool $all = false): TransferLine
    {
        $line = TransferLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
        $wheels = $all ? 0 : max(0, $line->wheels_out - 1);
        $line->update(['scanned_out' => false, 'qty_out' => null, 'wheels_out' => $wheels]);

        return $line;
    }

    /** Комплекты, погруженные не полностью, отгружаются с фактическим количеством колёс (с расхождением). */
    public function finalizePartialOut(Transfer $transfer): void
    {
        $partial = $transfer->lines()->where('scanned_out', false)->where('wheels_out', '>', 0)->get();
        foreach ($partial as $line) {
            $this->scanOutLine($line, $line->wheels_out);
        }
    }

    /** Отгрузка: отсканированные комплекты → В_ПУТИ, неотсканированные исключаются из перемещения. */
    public function ship(Transfer $transfer, ?string $driver = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $driver) {
            $transfer = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== TransferStatus::Created) {
                throw new DomainError('Перемещение уже отгружено или закрыто');
            }
            $this->finalizePartialOut($transfer);
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
        return DB::transaction(fn () => $this->scanInLine($this->findLine($transfer, $code), $qty));
    }

    /** Поштучный скан колеса при приёмке (см. countOut). */
    public function countIn(TransferLine $line): TransferLine
    {
        return DB::transaction(function () use ($line) {
            $transfer = Transfer::whereKey($line->transfer_id)->lockForUpdate()->firstOrFail();
            if (! in_array($transfer->status, [TransferStatus::Shipped, TransferStatus::Discrepancy], true)) {
                throw new DomainError('Перемещение не в пути');
            }
            $line = TransferLine::with('wheelSet')->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $expected = $line->qty_out ?? $line->wheelSet->qty;
            if ($line->scanned_in || $line->wheels_in >= $expected) {
                throw new DomainError("Лишний скан: комплект {$line->wheelSet->qr_code} уже принят полностью ({$line->wheels_in} из {$expected} кол.)");
            }
            $wheels = $line->wheels_in + 1;
            if ($wheels >= $expected) {
                return $this->scanInLine($line, $wheels === $line->wheelSet->qty ? null : $wheels);
            }
            $line->update(['wheels_in' => $wheels]);

            return $line->fresh(['wheelSet', 'transfer']);
        });
    }

    public function scanInLine(TransferLine $line, ?int $qty = null): TransferLine
    {
        return DB::transaction(function () use ($line, $qty) {
            $transfer = Transfer::with('to')->whereKey($line->transfer_id)->lockForUpdate()->firstOrFail();
            if (! in_array($transfer->status, [TransferStatus::Shipped, TransferStatus::Discrepancy], true)) {
                throw new DomainError('Перемещение не в пути');
            }
            if ($line->scanned_in) {
                throw new DomainError('Комплект уже принят');
            }
            $set = $this->mover->lock($line->wheel_set_id);
            $this->mover->confirmQty($set, $qty, "Приёмка {$transfer->number}");
            $line->update(['scanned_in' => true, 'qty_in' => $qty ?? $set->qty, 'wheels_in' => $qty ?? $set->qty]);

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
                $this->refreshTrip($transfer);
            }

            return $line->fresh(['wheelSet', 'transfer']);
        });
    }

    /** Завершить приёмку: всё сошлось → «Принято», иначе «Расхождение» + задание ответственному. */
    public function complete(Transfer $transfer): Transfer
    {
        return DB::transaction(function () use ($transfer) {
            // Не полностью принятые комплекты принимаются с фактическим количеством колёс
            $partial = $transfer->lines()->where('scanned_in', false)->where('wheels_in', '>', 0)->get();
            foreach ($partial as $line) {
                $this->scanInLine($line, $line->wheels_in);
            }
            $transfer = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status === TransferStatus::Received && $partial->isNotEmpty()) {
                return $transfer->fresh('lines.wheelSet');
            }
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
            $this->refreshTrip($transfer);

            return $transfer->fresh('lines.wheelSet');
        });
    }

    public function cancel(Transfer $transfer): Transfer
    {
        if ($transfer->status !== TransferStatus::Created) {
            throw new DomainError('Отменить можно только неотгруженное перемещение');
        }
        $transfer->lines()->update(['scanned_out' => false, 'qty_out' => null, 'wheels_out' => 0]);
        $transfer->update(['status' => TransferStatus::Cancelled, 'trip_id' => null]);

        return $transfer;
    }

    /** Ведомость закрывается, когда по всем её перемещениям завершена приёмка. */
    public function refreshTrip(Transfer $transfer): void
    {
        if (! $transfer->trip_id) {
            return;
        }
        $trip = Trip::whereKey($transfer->trip_id)->lockForUpdate()->first();
        if ($trip?->status !== TripStatus::Shipped) {
            return;
        }
        $open = $trip->transfers()->whereIn('status', [TransferStatus::Created->value, TransferStatus::Shipped->value])->exists();
        if (! $open) {
            $trip->update(['status' => TripStatus::Done, 'completed_at' => now()]);
        }
    }

    public function findLine(Transfer $transfer, string $code): TransferLine
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
