<?php

namespace App\Models;

use App\Domain\Storage\ContractStatus;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Договор (квитанция) хранения. Номер договора зашит в QR бирок. */
class StorageContract extends Model
{
    use Audited, HasUuids, SoftDeletes;

    protected $fillable = ['number', 'client_id', 'vehicle_id', 'store_id', 'tariff_id', 'accepted_by', 'accepted_at',
        'paid_until', 'storage_until', 'months', 'amount', 'overdue_fee', 'overdue_days', 'debt', 'debt_updated_at', 'status', 'onec_doc_guid', 'closed_at', 'notes'];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'paid_until' => 'date',
            'storage_until' => 'date',
            'debt_updated_at' => 'datetime',
            'closed_at' => 'datetime',
            'amount' => 'decimal:2',
            'overdue_fee' => 'decimal:2',
            'overdue_days' => 'integer',
            'months' => 'integer',
            'debt' => 'decimal:2',
            'status' => ContractStatus::class,
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'store_id');
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function sets(): HasMany
    {
        return $this->hasMany(WheelSet::class, 'contract_id')->orderBy('qr_code');
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(ContractExtension::class, 'contract_id')->orderByDesc('seq');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'contract_id')->latest('paid_at');
    }
}
