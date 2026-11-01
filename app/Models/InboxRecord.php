<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Обработанный запрос 1С (идемпотентность по Idempotency-Key). */
class InboxRecord extends Model
{
    protected $table = 'integration_inbox';

    public $timestamps = false;

    protected $fillable = ['idempotency_key', 'request_hash', 'method', 'path', 'response_status',
        'response_body', 'response_type', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
