<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Документ пролонгации (продления) договора хранения. */
class ContractExtension extends Model
{
    use HasUuids;

    protected $fillable = ['number', 'contract_id', 'seq', 'months', 'period_from', 'previous_until', 'storage_until',
        'amount', 'lines', 'created_by', 'outbox_event_id', 'onec_doc_guid', 'extended_at'];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'previous_until' => 'date',
            'storage_until' => 'date',
            'extended_at' => 'datetime',
            'amount' => 'decimal:2',
            'months' => 'integer',
            'seq' => 'integer',
            'lines' => 'array',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(StorageContract::class, 'contract_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class, 'outbox_event_id');
    }
}
