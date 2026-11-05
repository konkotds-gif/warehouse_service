<?php

namespace App\Models;

use App\Domain\Warehouse\LocationType;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Магазин или склад. Код склада = XX в адресе ячейки; код магазина — 'М01'.
 */
class Location extends Model
{
    use Audited;

    protected $fillable = ['code', 'name', 'type', 'address', 'building', 'is_active', 'onec_guid'];

    protected function casts(): array
    {
        return ['type' => LocationType::class, 'is_active' => 'boolean'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(StorageRow::class)->orderBy('row_no');
    }

    public function cells(): HasMany
    {
        return $this->hasMany(StorageCell::class);
    }

    public function isWarehouse(): bool
    {
        return $this->type === LocationType::Warehouse;
    }

    public function isStore(): bool
    {
        return $this->type === LocationType::Store;
    }

    /** Виртуальный адрес буфера магазина / склада без ячейки. */
    public function bufferAddress(): string
    {
        return $this->isStore() ? $this->code : $this->code.' (приёмка)';
    }

    /**
     * Точка в том же здании (склад и магазин под одной крышей): колёса не возит водитель,
     * их просто снимают с полки — перемещение проводится сразу, без рейса и ведомости.
     */
    public function sameBuildingAs(?Location $other): bool
    {
        return $other !== null && $this->building !== null && $other->id !== $this->id && $other->building === $this->building;
    }

    /** Другие точки этого здания. */
    public function buildingMates()
    {
        return $this->building === null ? collect()
            : static::where('building', $this->building)->whereKeyNot($this->id)->orderBy('type')->orderBy('code')->get();
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeStores(Builder $q): Builder
    {
        return $q->where('type', LocationType::Store->value);
    }

    public function scopeWarehouses(Builder $q): Builder
    {
        return $q->where('type', LocationType::Warehouse->value);
    }

    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }
}
