<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Заказ комплекта к выдаче, созданный онлайн-записью на услугу (код записи → комплект, магазин, время). */
class BookingOrder extends Model
{
    protected $fillable = ['booking_code', 'wheel_set_id', 'store_id', 'starts_at', 'phone', 'status'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime'];
    }

    public function wheelSet(): BelongsTo
    {
        return $this->belongsTo(WheelSet::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'store_id');
    }
}
