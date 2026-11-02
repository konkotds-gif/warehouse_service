<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Оплата из 1С. */
class Payment extends Model
{
    protected $fillable = ['contract_id', 'amount', 'paid_until', 'onec_doc_guid', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_until' => 'date', 'paid_at' => 'datetime'];
    }
}
