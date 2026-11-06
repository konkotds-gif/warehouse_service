<?php

namespace App\Domain\Integration\Booking;

use App\Domain\Storage\IssueService;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\DomainError;
use App\Models\BookingOrder;
use App\Models\Client;
use App\Models\Location;
use App\Models\WheelSet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Заказы к выдаче под запись на услугу: клиент записался на шиномонтаж с колёсами с хранения —
 * комплект заказывается к выдаче в магазин записи к её дате; перенос и отмена записи двигают или снимают заказ.
 */
class BookingOrders
{
    public function __construct(private readonly IssueService $issue, private readonly BookingStorage $storage) {}

    /** Телефон (подтверждённый на сайте записи) имеет право на комплект: владелец машины, доверенное лицо или клиент договора. */
    public function assertAccess(WheelSet $set, string $phone): void
    {
        $contract = $set->contract;
        $clients = $this->storage->clients($phone);
        $ok = $clients->contains(fn (Client $c) => $c->id === $contract->client_id
            || ($contract->vehicle && $c->canUseVehicle($contract->vehicle)));
        if (! $ok) {
            throw new DomainError('Комплект не относится к машинам этого клиента', 403);
        }
    }

    public function place(string $code, string $setId, string $storeCode, string $startsAt, string $phone): BookingOrder
    {
        $set = WheelSet::with('contract.vehicle')->find($setId) ?? throw DomainError::notFound('Комплект не найден');
        $this->assertAccess($set, $phone);
        $store = $this->store($storeCode);
        $at = CarbonImmutable::parse($startsAt)->timezone(config('app.display_timezone'));

        $existing = BookingOrder::where('booking_code', $code)->first();
        if ($existing && $existing->status === 'active') {
            if ($existing->wheel_set_id === $set->id) {
                return $this->update($code, $startsAt, $storeCode);
            }
            $this->cancel($code); // в записи выбрали другой комплект
            $existing->refresh();
        }

        return DB::transaction(function () use ($code, $set, $store, $at, $phone, $existing) {
            $taken = BookingOrder::activeBySet([$set->id])->first(fn (BookingOrder $o) => $o->booking_code !== $code);
            if ($taken) {
                throw new DomainError('Этот комплект уже заказан под другую запись ('.$taken->booking_code.')');
            }
            // Вторая ось: остальные комплекты договора того же сезона едут вместе с выбранным
            $extra = $this->companions($set, $code);
            foreach ([$set, ...$extra] as $s) {
                $s->refresh();
                $alreadyHere = in_array($s->status, [SetStatus::Ordered, SetStatus::Ready], true) && $s->issue_store_id === $store->id;
                if (! $alreadyHere) {
                    $this->issue->order($s, $store, $at->toDateString());
                }
            }
            $data = ['wheel_set_id' => $set->id, 'extra_set_ids' => $extra ? array_map(fn (WheelSet $s) => $s->id, $extra) : null,
                'store_id' => $store->id, 'starts_at' => $at->utc(), 'phone' => $phone, 'status' => 'active'];
            if ($existing) {
                $existing->update($data);

                return $existing->fresh();
            }

            return BookingOrder::create(['booking_code' => $code] + $data);
        });
    }

    /** Перенос записи: другая дата — сдвигаем срок выдачи; другой магазин — перезаказ. */
    public function update(string $code, string $startsAt, ?string $storeCode = null): BookingOrder
    {
        $order = $this->active($code);
        $at = CarbonImmutable::parse($startsAt)->timezone(config('app.display_timezone'));
        $store = $storeCode ? $this->store($storeCode) : $order->store;

        return DB::transaction(function () use ($order, $at, $store) {
            foreach (WheelSet::whereIn('id', $order->setIds())->lockForUpdate()->get() as $set) {
                if ($store->id !== $order->store_id) {
                    if (in_array($set->status, [SetStatus::Ordered, SetStatus::Ready], true)) {
                        $this->issue->cancelOrder($set);
                    }
                    $this->issue->order($set->fresh(), $store, $at->toDateString());
                } elseif ($set->status === SetStatus::Ordered) {
                    $set->update(['issue_due_date' => $at->toDateString()]);
                }
            }
            $order->update(['store_id' => $store->id, 'starts_at' => $at->utc()]);

            return $order->fresh();
        });
    }

    /** Отмена записи: снимаем заказ, если комплект ещё не выдан. Уже привезённые в магазин колёса остаются там. */
    public function cancel(string $code): ?BookingOrder
    {
        $order = BookingOrder::where('booking_code', $code)->first();
        if (! $order || $order->status !== 'active') {
            return $order;
        }
        DB::transaction(function () use ($order) {
            foreach (WheelSet::whereIn('id', $order->setIds())->get() as $set) {
                if ($set->status === SetStatus::Ordered
                    || ($set->status === SetStatus::Ready && IssueService::buildingIssueTransfer($set))) {
                    $this->issue->cancelOrder($set);
                }
            }
            $order->update(['status' => 'cancelled']);
        });

        return $order->fresh();
    }

    /** @return array<string, mixed> */
    public function present(BookingOrder $order): array
    {
        $set = $order->wheelSet;
        if ($order->status === 'active' && $set && $set->status === SetStatus::Issued) {
            $order->update(['status' => 'issued']);
        }

        return [
            'booking_code' => $order->booking_code,
            'status' => $order->status,
            'set_id' => $order->wheel_set_id,
            'set_ids' => $order->setIds(),
            'qr_code' => $set?->qr_code,
            'contract_number' => $set?->contract?->number,
            'store_code' => $order->store?->code,
            'starts_at' => $order->starts_at->toIso8601String(),
            'set_state' => $set ? BookingStorage::state($set->status) : null,
            'set_state_label' => $set ? BookingStorage::stateLabel($set->status) : null,
        ];
    }

    /**
     * Комплекты, которые едут вместе с выбранным: тот же договор и сезон, лежат на хранении и не заказаны под другую запись.
     * Так разноширокие оси (два комплекта по 2 колеса) приезжают в магазин обе.
     *
     * @return list<WheelSet>
     */
    private function companions(WheelSet $set, string $code): array
    {
        if ($set->season === null) {
            return [];
        }
        $candidates = WheelSet::where('contract_id', $set->contract_id)->whereKeyNot($set->id)
            ->where('season', $set->season->value)
            ->whereIn('status', [SetStatus::Placed->value, SetStatus::Received->value, SetStatus::Accepted->value, SetStatus::Ordered->value, SetStatus::Ready->value])
            ->get();
        $taken = BookingOrder::activeBySet($candidates->pluck('id'));

        return $candidates->filter(function (WheelSet $s) use ($taken, $code) {
            $o = $taken->get($s->id);
            if ($o) {
                return $o->booking_code === $code; // уже в этом заказе
            }

            // Заказанные вручную (не под запись) не трогаем
            return in_array($s->status, [SetStatus::Placed, SetStatus::Received, SetStatus::Accepted], true);
        })->values()->all();
    }

    private function active(string $code): BookingOrder
    {
        $order = BookingOrder::where('booking_code', $code)->where('status', 'active')->first();

        return $order ?? throw DomainError::notFound('Заказ под эту запись не найден');
    }

    private function store(string $code): Location
    {
        $store = Location::where('code', $code)->where('is_active', true)->first();
        if (! $store || ! $store->isStore()) {
            throw DomainError::invalid('Магазин с кодом '.$code.' не найден в системе хранения', 'store_code');
        }

        return $store;
    }
}
