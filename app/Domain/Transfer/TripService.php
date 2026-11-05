<?php

namespace App\Domain\Transfer;

use App\Domain\Storage\ScanResolver;
use App\Domain\Support\DomainError;
use App\Domain\Support\NumberGenerator;
use App\Domain\Warehouse\TransferStatus;
use App\Domain\Warehouse\TripStatus;
use App\Models\Transfer;
use App\Models\TransferLine;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ведомость заданий на перевозку (рейс).
 *
 * Перемещения создаются без сканирования и попадают в пул «Перевозки». Когда приезжает водитель,
 * из пула формируется ведомость по одному или нескольким перемещениям; бирки сканируются при погрузке
 * сразу по всей ведомости, и отгрузка идёт по факту скана (сверка «запланировано / погружено»).
 */
final class TripService
{
    public function __construct(
        private readonly TransferService $transfers,
        private readonly NumberGenerator $numbers,
        private readonly ScanResolver $resolver,
    ) {}

    /** Перемещения, ожидающие погрузки и ещё не включённые в ведомость. */
    public function pool()
    {
        return Transfer::query()->where('status', TransferStatus::Created->value)->whereNull('trip_id');
    }

    /** @param list<string> $transferIds */
    public function create(array $transferIds, ?string $driver = null, ?string $vehicle = null, ?User $user = null): Trip
    {
        if ($transferIds === []) {
            throw DomainError::invalid('Выберите перемещения для ведомости');
        }

        return DB::transaction(function () use ($transferIds, $driver, $vehicle, $user) {
            $trip = Trip::create([
                'number' => $this->numbers->tripNumber(),
                'status' => TripStatus::Loading,
                'driver' => $driver,
                'vehicle' => $vehicle,
                'created_by' => $user?->id,
            ]);
            $this->attach($trip, $transferIds);

            return $trip->fresh('transfers');
        });
    }

    /** @param list<string> $transferIds */
    public function addTransfers(Trip $trip, array $transferIds): Trip
    {
        return DB::transaction(function () use ($trip, $transferIds) {
            $trip = $this->lockLoading($trip);
            $this->attach($trip, $transferIds);

            return $trip->fresh('transfers');
        });
    }

    /** Вернуть перемещение из ведомости в пул (отметки погрузки сбрасываются). */
    public function detach(Trip $trip, Transfer $transfer): Trip
    {
        return DB::transaction(function () use ($trip, $transfer) {
            $trip = $this->lockLoading($trip);
            if ($transfer->trip_id !== $trip->id) {
                throw DomainError::notFound("Перемещение {$transfer->number} не входит в ведомость {$trip->number}");
            }
            $transfer->lines()->update(['scanned_out' => false, 'qty_out' => null, 'wheels_out' => 0]);
            $transfer->update(['trip_id' => null]);

            return $trip->fresh('transfers');
        });
    }

    public function update(Trip $trip, ?string $driver, ?string $vehicle): Trip
    {
        $trip->update(['driver' => $driver, 'vehicle' => $vehicle]);
        $trip->transfers()->where('status', TransferStatus::Created->value)->update(['driver' => $driver]);

        return $trip;
    }

    /** Скан бирки при погрузке: ищем комплект среди всех перемещений ведомости. */
    public function scanOut(Trip $trip, string $code, ?int $qty = null): TransferLine
    {
        return DB::transaction(function () use ($trip, $code, $qty) {
            $trip = $this->lockLoading($trip);
            $line = $this->findLine($trip, $code, fn (TransferLine $l) => ! $l->scanned_out, [TransferStatus::Created]);

            // Без количества — поштучно: один скан = одно колесо
            return $qty === null ? $this->transfers->countOut($line) : $this->transfers->scanOutLine($line, $qty);
        });
    }

    /** Отменить скан одного колеса или всего комплекта (отсканировали не то / колесо не поместилось). */
    public function unscan(Trip $trip, TransferLine $line, bool $all = true): void
    {
        DB::transaction(function () use ($trip, $line, $all) {
            $trip = $this->lockLoading($trip);
            if (! $trip->transfers()->whereKey($line->transfer_id)->exists()) {
                throw DomainError::notFound('Строка не относится к ведомости');
            }
            $this->transfers->uncountOut($line, $all);
        });
    }

    /**
     * Отгрузка по факту скана. Перемещения без единого скана возвращаются в пул;
     * неотсканированные комплекты частично погруженного перемещения выносятся в новое перемещение
     * (тоже в пул), чтобы задание на их перевозку не потерялось.
     *
     * @return array{trip: Trip, shipped: Collection, returned: Collection}
     */
    public function ship(Trip $trip): array
    {
        return DB::transaction(function () use ($trip) {
            $trip = $this->lockLoading($trip);
            foreach ($trip->transfers as $transfer) {
                $this->transfers->finalizePartialOut($transfer);
            }
            $transfers = $trip->transfers()->with('lines')->where('status', TransferStatus::Created->value)->get();
            if (! $transfers->contains(fn ($t) => $t->lines->contains('scanned_out', true))) {
                throw new DomainError('Не отсканировано ни одного комплекта');
            }
            $shipped = collect();
            $returned = collect();
            foreach ($transfers as $transfer) {
                $missing = $transfer->lines->where('scanned_out', false);
                if ($missing->count() === $transfer->lines->count()) {
                    $transfer->update(['trip_id' => null]);
                    $returned->push($transfer);

                    continue;
                }
                if ($missing->isNotEmpty()) {
                    $rest = Transfer::create([
                        'number' => $this->numbers->transferNumber(),
                        'from_location_id' => $transfer->from_location_id,
                        'to_location_id' => $transfer->to_location_id,
                        'status' => TransferStatus::Created,
                        'purpose' => $transfer->purpose,
                        'created_by' => $transfer->created_by,
                        'notes' => "Не погружено по ведомости {$trip->number} (из {$transfer->number})",
                    ]);
                    TransferLine::whereIn('id', $missing->pluck('id'))->update(['transfer_id' => $rest->id]);
                    $returned->push($rest);
                }
                $shipped->push($this->transfers->ship($transfer, $trip->driver));
            }
            $trip->update(['status' => TripStatus::Shipped, 'shipped_at' => now()]);

            return ['trip' => $trip->fresh('transfers'), 'shipped' => $shipped, 'returned' => $returned];
        });
    }

    /** Скан при приёмке по ведомости (водитель привёз несколько перемещений). */
    public function scanIn(Trip $trip, string $code, ?int $qty = null): TransferLine
    {
        return DB::transaction(function () use ($trip, $code, $qty) {
            $trip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if ($trip->status !== TripStatus::Shipped) {
                throw new DomainError('Ведомость не в пути');
            }
            $line = $this->findLine($trip, $code, fn (TransferLine $l) => ! $l->scanned_in,
                [TransferStatus::Shipped, TransferStatus::Discrepancy]);

            return $qty === null ? $this->transfers->countIn($line) : $this->transfers->scanInLine($line, $qty);
        });
    }

    /** Завершить приёмку по всем перемещениям ведомости. */
    public function complete(Trip $trip): Trip
    {
        return DB::transaction(function () use ($trip) {
            foreach ($trip->transfers()->where('status', TransferStatus::Shipped->value)->get() as $transfer) {
                $this->transfers->complete($transfer);
            }
            $trip = $trip->fresh();
            if ($trip->status === TripStatus::Shipped) {
                $trip->update(['status' => TripStatus::Done, 'completed_at' => now()]);
            }

            return $trip->fresh('transfers');
        });
    }

    public function cancel(Trip $trip): Trip
    {
        return DB::transaction(function () use ($trip) {
            $trip = $this->lockLoading($trip);
            foreach ($trip->transfers as $transfer) {
                $transfer->lines()->update(['scanned_out' => false, 'qty_out' => null, 'wheels_out' => 0]);
                $transfer->update(['trip_id' => null]);
            }
            $trip->update(['status' => TripStatus::Cancelled]);

            return $trip;
        });
    }

    /** @param list<string> $transferIds */
    private function attach(Trip $trip, array $transferIds): void
    {
        $transfers = Transfer::whereIn('id', $transferIds)->lockForUpdate()->get();
        if ($transfers->count() !== count(array_unique($transferIds))) {
            throw DomainError::notFound('Часть перемещений не найдена');
        }
        foreach ($transfers as $transfer) {
            if ($transfer->status !== TransferStatus::Created) {
                throw new DomainError("Перемещение {$transfer->number} уже отгружено или закрыто");
            }
            if ($transfer->trip_id && $transfer->trip_id !== $trip->id) {
                throw new DomainError("Перемещение {$transfer->number} уже включено в другую ведомость");
            }
            $transfer->update(['trip_id' => $trip->id, 'driver' => $trip->driver ?? $transfer->driver]);
        }
    }

    private function lockLoading(Trip $trip): Trip
    {
        $trip = Trip::with('transfers')->whereKey($trip->id)->lockForUpdate()->firstOrFail();
        if ($trip->status !== TripStatus::Loading) {
            throw new DomainError("Ведомость {$trip->number} уже отгружена или закрыта");
        }

        return $trip;
    }

    /**
     * @param  callable(TransferLine): bool  $pending
     * @param  list<TransferStatus>  $statuses
     */
    private function findLine(Trip $trip, string $code, callable $pending, array $statuses): TransferLine
    {
        $setIds = $this->resolver->sets($code)->pluck('id');
        if ($setIds->isEmpty()) {
            throw DomainError::notFound("Бирка «{$code}» не найдена");
        }
        $lines = TransferLine::with('transfer')->whereIn('wheel_set_id', $setIds)
            ->whereIn('transfer_id', $trip->transfers()->whereIn('status', array_map(fn ($s) => $s->value, $statuses))->select('id'))
            ->get();
        if ($lines->isEmpty()) {
            $other = TransferLine::with('transfer')->whereIn('wheel_set_id', $setIds)
                ->whereHas('transfer', fn ($q) => $q->where('status', TransferStatus::Created->value))->first();
            throw new DomainError($other
                ? "Комплект «{$code}» в перемещении {$other->transfer->number}, которого нет в ведомости {$trip->number}"
                : "Комплект «{$code}» не входит в ведомость {$trip->number}");
        }

        return $lines->first($pending) ?? $lines->first();
    }
}
