<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** Заказ комплекта к выдаче, созданный онлайн-записью на услугу (код записи → комплект, магазин, время). */
class BookingOrder extends Model
{
    protected $fillable = ['booking_code', 'wheel_set_id', 'extra_set_ids', 'store_id', 'starts_at', 'phone', 'status'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'extra_set_ids' => 'array'];
    }

    public function wheelSet(): BelongsTo
    {
        return $this->belongsTo(WheelSet::class);
    }

    /** Все комплекты заказа: основной и заказанные вместе с ним (вторая ось). @return list<string> */
    public function setIds(): array
    {
        return array_values(array_unique(array_filter([$this->wheel_set_id, ...($this->extra_set_ids ?? [])])));
    }

    /** Активные заказы по каждому комплекту (основному и дополнительным). @param iterable<string> $setIds */
    public static function activeBySet(iterable $setIds): Collection
    {
        $ids = collect($setIds)->values();
        $map = collect();
        if ($ids->isEmpty()) {
            return $map;
        }
        foreach (static::where('status', 'active')->get() as $o) {
            foreach ($o->setIds() as $id) {
                if ($ids->contains($id)) {
                    $map[$id] = $o;
                }
            }
        }

        return $map;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'store_id');
    }
}
