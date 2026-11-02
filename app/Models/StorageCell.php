<?php

namespace App\Models;

use App\Domain\Storage\SetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ячейка хранения с адресом XX_YY_ZZ (склад_ряд_ячейка).
 */
class StorageCell extends Model
{
    public const ADDRESS_REGEX = '/^\d{2}_\d{2}_\d{2}$/';

    /** Статусы, при которых комплект занимает ячейку. */
    public const OCCUPYING_STATUSES = ['placed', 'ordered'];

    protected $fillable = ['row_id', 'location_id', 'cell_no', 'address', 'capacity', 'is_blocked'];

    protected function casts(): array
    {
        return ['is_blocked' => 'boolean', 'capacity' => 'integer'];
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(StorageRow::class, 'row_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function sets(): HasMany
    {
        return $this->hasMany(WheelSet::class, 'current_cell_id')
            ->whereIn('status', [SetStatus::Placed->value, SetStatus::Ordered->value]);
    }

    public static function isValidAddress(string $address): bool
    {
        return (bool) preg_match(self::ADDRESS_REGEX, $address);
    }

    public static function makeAddress(string $locationCode, string $rowNo, string $cellNo): string
    {
        return sprintf('%02d_%02d_%02d', (int) $locationCode, (int) $rowNo, (int) $cellNo);
    }
}
