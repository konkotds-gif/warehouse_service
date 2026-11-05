<?php

namespace App\Domain\Storage;

use App\Domain\Integration\OneC\Outbox;
use App\Domain\Support\DomainError;
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
    public function __construct(private readonly Outbox $outbox, private readonly Pricing $pricing) {}

    public function extend(StorageContract $contract, int $months, ?User $user = null): StorageContract
    {
        $this->pricing->validateMonths($months);
        if ($contract->status === ContractStatus::Closed) {
            throw new DomainError('Договор закрыт');
        }

        return DB::transaction(function () use ($contract, $months) {
            $contract = StorageContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $base = $contract->storage_until && $contract->storage_until->isFuture() ? $contract->storage_until->copy() : now();
            $lines = $this->pricing->extensionLines($contract, $months);
            $extra = round(array_sum(array_column($lines, 'amount')), 2);
            $contract->update([
                'storage_until' => $base->addMonthsNoOverflow($months)->toDateString(),
                'months' => (int) $contract->months + $months,
                'amount' => (float) $contract->amount + $extra,
                'status' => $contract->status === ContractStatus::Overdue ? ContractStatus::Open : $contract->status,
            ]);
            $this->outbox->record('contract.extended', $this->outbox->contractPayload($contract) + [
                'extended_months' => $months, 'extra_amount' => $extra,
                'extension_lines' => array_map(fn ($l) => [
                    'qr_code' => $l['set']->qr_code,
                    'service_code' => $l['tariff']->code,
                    'service_onec_guid' => $l['tariff']->onec_guid,
                    'diameter' => $l['diameter'],
                    'qty' => $l['qty'],
                    'months' => $l['months'],
                    'price' => $l['price'],
                    'amount' => $l['amount'],
                ], $lines),
            ]);

            return $contract;
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
