<?php

namespace App\Models;

use App\Domain\Storage\Condition;
use App\Domain\Storage\Season;
use App\Domain\Storage\SetStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Комплект на хранении в рамках договора (снимок «как приняли»).
 * QR на бирке не меняется никогда, адрес — при каждом перемещении.
 */
class WheelSet extends Model
{
    use HasUuids;

    protected $fillable = ['garage_set_id', 'contract_id', 'qr_code', 'qty', 'tire_size', 'with_rims', 'condition',
        'season', 'brand_model', 'damages', 'status', 'current_cell_id', 'current_location_id', 'transfer_id',
        'issue_store_id', 'issue_due_date', 'notes'];

    protected function casts(): array
    {
        return [
            'with_rims' => 'boolean',
            'damages' => 'array',
            'qty' => 'integer',
            'condition' => Condition::class,
            'season' => Season::class,
            'status' => SetStatus::class,
            'issue_due_date' => 'date',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(StorageContract::class, 'contract_id');
    }

    public function garageSet(): BelongsTo
    {
        return $this->belongsTo(GarageSet::class);
    }

    public function cell(): BelongsTo
    {
        return $this->belongsTo(StorageCell::class, 'current_cell_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    public function issueStore(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'issue_store_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class)->orderByDesc('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function tagPrints(): HasMany
    {
        return $this->hasMany(TagPrint::class)->orderByDesc('id');
    }

    /**
     * Текущий адрес: ячейка XX_YY_ZZ или виртуальный (М05, TR-…, ISSUED).
     */
    public function currentAddress(): string
    {
        return match (true) {
            $this->status === SetStatus::Issued => 'ISSUED',
            $this->status === SetStatus::Disposed => 'DISPOSED',
            $this->status === SetStatus::InTransit => 'TR-'.($this->transfer?->number ?? '?'),
            $this->current_cell_id !== null => $this->cell?->address ?? '?',
            $this->current_location_id !== null => $this->location?->bufferAddress() ?? '?',
            default => '—',
        };
    }

    public function title(): string
    {
        return sprintf('%s · %d шт · %s · %s', $this->tire_size, $this->qty,
            $this->with_rims ? 'с дисками' : 'без дисков', $this->condition->label());
    }
}
