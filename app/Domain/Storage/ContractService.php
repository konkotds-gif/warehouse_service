<?php

namespace App\Domain\Storage;

use App\Domain\Integration\OneC\Outbox;
use App\Domain\Support\DomainError;
use App\Domain\Support\NumberGenerator;
use App\Models\ContractExtension;
use App\Models\Payment;
use App\Models\StorageContract;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Продление, оплаты и долг из 1С, просрочки.
 */
final class ContractService
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly Pricing $pricing,
        private readonly NumberGenerator $numbers,
    ) {}

    /**
     * Продление хранения: создаётся документ пролонгации (ПР00001) со стоимостью по текущему прайсу,
     * договор получает новую дату окончания, в 1С уходит contract.extended с реквизитами документа.
     */
    public function extend(StorageContract $contract, int $months, ?User $user = null): ContractExtension
    {
        $this->pricing->validateMonths($months);
        if ($contract->status === ContractStatus::Closed) {
            throw new DomainError('Договор закрыт');
        }

        return DB::transaction(function () use ($contract, $months, $user) {
            $contract = StorageContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $previous = $contract->storage_until?->copy();
            $base = $previous && $previous->isFuture() ? $previous->copy() : now()->startOfDay();
            $lines = array_map(fn ($l) => [
                'qr_code' => $l['set']->qr_code,
                'tire_size' => $l['set']->tire_size,
                'diameter' => $l['diameter'],
                'service_code' => $l['tariff']->code,
                'service_onec_guid' => $l['tariff']->onec_guid,
                'service_name' => $l['tariff']->name,
                'qty' => $l['qty'],
                'months' => $l['months'],
                'price' => $l['price'],
                'amount' => $l['amount'],
            ], $this->pricing->extensionLines($contract, $months));
            if ($lines === []) {
                throw new DomainError('В договоре нет комплектов на хранении — продлевать нечего');
            }
            $extra = round(array_sum(array_column($lines, 'amount')), 2);
            $until = $base->copy()->addMonthsNoOverflow($months);

            $contract->update([
                'storage_until' => $until->toDateString(),
                'months' => (int) $contract->months + $months,
                'amount' => (float) $contract->amount + $extra,
                'status' => $contract->status === ContractStatus::Overdue ? ContractStatus::Open : $contract->status,
            ]);
            $extension = ContractExtension::create([
                'number' => $this->numbers->extensionNumber(),
                'contract_id' => $contract->id,
                'seq' => (int) $contract->extensions()->max('seq') + 1,
                'months' => $months,
                'period_from' => $base->toDateString(),
                'previous_until' => $previous?->toDateString(),
                'storage_until' => $until->toDateString(),
                'amount' => $extra,
                'lines' => $lines,
                'created_by' => $user?->id,
                'extended_at' => now(),
            ]);
            $event = $this->outbox->record('contract.extended', $this->outbox->contractPayload($contract) + [
                'extended_months' => $months, 'extra_amount' => $extra,
                'extension' => $this->outbox->extensionPayload($extension),
                'extension_lines' => array_map(fn ($l) => array_diff_key($l, ['tire_size' => 1, 'service_name' => 1]), $lines),
            ]);
            $extension->update(['outbox_event_id' => $event->id]);

            return $extension;
        });
    }

    /** Оплата из 1С (идемпотентна по onec_doc_guid). */
    public function applyPayment(StorageContract $contract, float $amount, ?string $paidUntil, ?string $docGuid, ?string $paidAt = null): Payment
    {
        return DB::transaction(function () use ($contract, $amount, $paidUntil, $docGuid, $paidAt) {
            if ($docGuid && ($existing = Payment::where('onec_doc_guid', $docGuid)->first())) {
                return $existing;
            }
            $contract = StorageContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::create([
                'contract_id' => $contract->id,
                'amount' => $amount,
                'paid_until' => $paidUntil,
                'onec_doc_guid' => $docGuid,
                'paid_at' => $paidAt ? Carbon::parse($paidAt) : now(),
            ]);
            $changes = [];
            if ($paidUntil) {
                $changes['paid_until'] = $paidUntil;
                if ($contract->status === ContractStatus::Overdue && Carbon::parse($paidUntil)->gte(now()->startOfDay())) {
                    $changes['status'] = ContractStatus::Open;
                }
                if (! $contract->storage_until || $contract->storage_until->lt(Carbon::parse($paidUntil))) {
                    $changes['storage_until'] = $paidUntil;
                }
            }
            if ($changes) {
                $contract->update($changes);
            }

            return $payment;
        });
    }

    public function setDebt(StorageContract $contract, float $debt): StorageContract
    {
        $contract->update(['debt' => max(0, $debt), 'debt_updated_at' => now()]);

        return $contract;
    }

    /**
     * Пометить просроченные договоры: срок хранения (или «оплачено до») истёк, колёса не забрали.
     *
     * @return int сколько договоров помечено
     */
    public function markOverdue(?Carbon $today = null): int
    {
        $today = ($today ?? now())->toDateString();
        $count = 0;
        StorageContract::where('status', ContractStatus::Open->value)
            ->where(function ($q) use ($today) {
                $q->where(fn ($q) => $q->whereNotNull('paid_until')->where('paid_until', '<', $today)
                    ->where(fn ($q) => $q->whereNull('storage_until')->orWhere('storage_until', '<', $today)))
                    ->orWhere(fn ($q) => $q->whereNull('paid_until')->where('storage_until', '<', $today));
            })
            ->orderBy('id')
            ->chunkById(200, function ($contracts) use (&$count) {
                foreach ($contracts as $contract) {
                    DB::transaction(function () use ($contract) {
                        $contract->update(['status' => ContractStatus::Overdue]);
                        $this->outbox->record('set.overdue', $this->outbox->contractPayload($contract));
                    });
                    $count++;
                }
            });

        return $count;
    }
}
