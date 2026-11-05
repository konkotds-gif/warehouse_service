<?php

namespace App\Domain\Integration\OneC;

use App\Models\ContractExtension;
use App\Models\OutboxEvent;
use App\Models\StorageContract;
use App\Models\Transfer;
use App\Models\WheelSet;
use Illuminate\Support\Facades\DB;

/**
 * Запись событий для 1С в integration_outbox. Вызывать внутри той же транзакции,
 * что и изменение данных — тогда ни одно событие не потеряется.
 */
final class Outbox
{
    public const TYPES = [
        'contract.created', 'contract.extended', 'contract.closed', 'set.issued', 'set.overdue',
        'transfer.completed', 'client.created',
    ];

    public function record(string $type, array $data): OutboxEvent
    {
        if (DB::transactionLevel() === 0) {
            // Защита от ошибок разработки: outbox пишется только в транзакции
            return DB::transaction(fn () => $this->record($type, $data));
        }

        return OutboxEvent::create(['event_type' => $type, 'payload' => $data, 'created_at' => now()]);
    }

    public function contractPayload(StorageContract $contract): array
    {
        $contract->loadMissing(['client', 'vehicle', 'store', 'tariff', 'sets.tariff', 'sets.cell', 'sets.location', 'sets.transfer']);

        return [
            'contract_number' => $contract->number,
            'contract_id' => $contract->id,
            'status' => $contract->status->value,
            'client_id' => $contract->client_id,
            'client_onec_guid' => $contract->client?->onec_guid,
            'client_name' => $contract->client?->full_name,
            'client_phone' => $contract->client?->phone,
            'vehicle' => $contract->vehicle ? [
                'plate' => $contract->vehicle->plate,
                'make' => $contract->vehicle->make,
                'model' => $contract->vehicle->model,
            ] : null,
            'store_code' => $contract->store?->code,
            'store_onec_guid' => $contract->store?->onec_guid,
            'service_code' => $contract->tariff?->code,
            'service_onec_guid' => $contract->tariff?->onec_guid,
            'accepted_at' => $contract->accepted_at?->toIso8601String(),
            'storage_until' => $contract->storage_until?->toDateString(),
            'months' => $contract->months,
            'paid_until' => $contract->paid_until?->toDateString(),
            'amount' => (float) $contract->amount,
            'onec_doc_guid' => $contract->onec_doc_guid,
            'overdue_fee' => (float) $contract->overdue_fee,
            'overdue_days' => (int) $contract->overdue_days,
            'closed_at' => $contract->closed_at?->toIso8601String(),
            'close_confirmation' => $contract->close_confirmation,
            'close_phone' => $contract->close_phone,
            'sets' => $contract->sets->map(fn (WheelSet $s) => $this->setPayload($s))->values()->all(),
        ];
    }

    /** Реквизиты документа пролонгации для 1С. */
    public function extensionPayload(ContractExtension $e): array
    {
        return [
            'id' => $e->id,
            'number' => $e->number,
            'seq' => $e->seq,
            'date' => $e->extended_at->toIso8601String(),
            'months' => $e->months,
            'period_from' => $e->period_from->toDateString(),
            'previous_until' => $e->previous_until?->toDateString(),
            'storage_until' => $e->storage_until->toDateString(),
            'amount' => (float) $e->amount,
        ];
    }

    public function setPayload(WheelSet $set): array
    {
        return [
            'qr_code' => $set->qr_code,
            'qty' => $set->qty,
            'tire_size' => $set->tire_size,
            'with_rims' => $set->with_rims,
            'condition' => $set->condition->value,
            'season' => $set->season?->value,
            'status' => $set->status->value,
            'address' => $set->currentAddress(),
            // Строка услуги для документа 1С: номенклатура по диаметру, цена за колесо в месяц
            'service_code' => $set->tariff?->code,
            'service_onec_guid' => $set->tariff?->onec_guid,
            'price' => $set->price !== null ? (float) $set->price : null,
            'amount' => (float) $set->amount,
        ];
    }

    public function transferPayload(Transfer $transfer): array
    {
        $transfer->loadMissing(['from', 'to', 'lines.wheelSet']);

        return [
            'transfer_number' => $transfer->number,
            'status' => $transfer->status->value,
            'from_code' => $transfer->from->code,
            'from_onec_guid' => $transfer->from->onec_guid,
            'to_code' => $transfer->to->code,
            'to_onec_guid' => $transfer->to->onec_guid,
            'shipped_at' => $transfer->shipped_at?->toIso8601String(),
            'received_at' => $transfer->received_at?->toIso8601String(),
            'sets' => $transfer->lines->map(fn ($l) => [
                'qr_code' => $l->wheelSet->qr_code,
                'scanned_out' => $l->scanned_out,
                'scanned_in' => $l->scanned_in,
            ])->values()->all(),
        ];
    }
}
