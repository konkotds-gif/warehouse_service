<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Storage\ContractService;
use App\Http\Api\ApiResponse;
use App\Models\Client;
use App\Models\OutboxEvent;
use App\Models\StorageContract;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Очередь событий (платформа → 1С) и деньги (1С → платформа).
 */
class IntegrationController extends ApiController
{
    /**
     * Новые события после указанного id (курсор). 1С хранит последний обработанный id сама.
     */
    public function events(Request $request): Response
    {
        $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'undelivered' => ['nullable', 'boolean'],
        ]);
        $after = (int) $request->query('after', 0);
        $limit = (int) $request->query('limit', 100);
        $events = OutboxEvent::where('id', '>', $after)
            ->when($request->boolean('undelivered'), fn ($q) => $q->whereNull('delivered_at'))
            ->orderBy('id')->limit($limit)->get();

        if ($events->isNotEmpty()) {
            OutboxEvent::whereIn('id', $events->pluck('id'))->whereNull('delivered_at')->increment('attempts');
        }

        $xml = ApiResponse::wantsXml($request);
        $items = $events->map(function (OutboxEvent $e) use ($xml) {
            $base = ['id' => $e->id, 'type' => $e->event_type, 'created_at' => $e->created_at->toIso8601String()];
            if ($xml) {
                return ['@attributes' => $base] + $e->payload;
            }

            return $base + ['delivered' => $e->delivered_at !== null, 'data' => $e->payload];
        })->all();

        return $this->respond([
            'events' => $items,
            'last_id' => $events->last()?->id ?? $after,
            'has_more' => $events->count() === $limit,
        ], 200, 'response');
    }

    /**
     * Подтверждение обработки события + GUID созданного документа 1С.
     * Принимает одно подтверждение {event_id, onec_doc_guid} или пакет {events: [...]}.
     * Для client.created передайте client_onec_guid — платформа запишет GUID контрагента.
     */
    public function ack(Request $request): Response
    {
        $items = $request->input('events');
        if (! is_array($items) || $items === []) {
            $items = [$request->only(['event_id', 'id', 'onec_doc_guid', 'client_onec_guid', 'note'])];
        } elseif (! array_is_list($items)) {
            $items = [$items];
        }

        $results = DB::transaction(function () use ($items) {
            $out = [];
            foreach ($items as $item) {
                $id = (int) ($item['event_id'] ?? $item['id'] ?? 0);
                $event = OutboxEvent::lockForUpdate()->find($id);
                if (! $event) {
                    $out[] = ['event_id' => $id, 'status' => 'not_found'];

                    continue;
                }
                $docGuid = $item['onec_doc_guid'] ?? null;
                $event->update([
                    'delivered_at' => $event->delivered_at ?? now(),
                    'onec_doc_guid' => $docGuid ?: $event->onec_doc_guid,
                    'ack_note' => isset($item['note']) ? mb_substr((string) $item['note'], 0, 255) : $event->ack_note,
                ]);
                $this->applyAck($event, $docGuid, $item['client_onec_guid'] ?? null);
                $out[] = ['event_id' => $id, 'status' => 'ok'];
            }

            return $out;
        });

        return $this->respond(['results' => $results], 200, 'ack');
    }

    private function applyAck(OutboxEvent $event, ?string $docGuid, ?string $clientGuid): void
    {
        $p = $event->payload;
        if ($docGuid && in_array($event->event_type, ['contract.created', 'contract.extended'], true) && ! empty($p['contract_number'])) {
            StorageContract::where('number', $p['contract_number'])->whereNull('onec_doc_guid')->update(['onec_doc_guid' => $docGuid]);
        }
        if ($event->event_type === 'client.created' && ! empty($p['client_id'])) {
            $guid = $clientGuid ?: $docGuid;
            if ($guid) {
                Client::whereKey($p['client_id'])->whereNull('onec_guid')->update(['onec_guid' => $guid]);
            }
        }
    }

    /** Оплата: договор, сумма, документ 1С, «оплачено до». */
    public function payment(Request $request, ContractService $service): Response
    {
        $data = $request->validate([
            'contract' => ['required', 'string'],
            'amount' => ['required', 'numeric'],
            'paid_until' => ['nullable', 'date'],
            'onec_doc_guid' => ['nullable', 'uuid'],
            'paid_at' => ['nullable', 'date'],
        ]);
        $contract = StorageContract::where('number', $data['contract'])->firstOrFail();
        $payment = $service->applyPayment($contract, (float) $data['amount'], $data['paid_until'] ?? null,
            $data['onec_doc_guid'] ?? null, $data['paid_at'] ?? null);

        return $this->respond([
            'payment_id' => $payment->id,
            'contract' => $contract->number,
            'paid_until' => $contract->fresh()->paid_until?->toDateString(),
            'status' => $contract->fresh()->status->value,
        ], $payment->wasRecentlyCreated ? 201 : 200, 'payment');
    }

    /** Текущий долг клиента по договору (для блокировки выдачи). */
    public function debt(Request $request, string $number, ContractService $service): Response
    {
        $data = $request->validate(['debt' => ['required', 'numeric', 'min:0']]);
        $contract = StorageContract::where('number', $number)->firstOrFail();
        $service->setDebt($contract, (float) $data['debt']);

        return $this->respond(['contract' => $contract->number, 'debt' => (float) $contract->debt,
            'debt_updated_at' => $contract->debt_updated_at->toIso8601String()], 200, 'debt');
    }
}
