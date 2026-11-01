<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Событие для 1С (transactional outbox). */
class OutboxEvent extends Model
{
    protected $table = 'integration_outbox';

    public $timestamps = false;

    protected $fillable = ['event_type', 'payload', 'created_at', 'delivered_at', 'attempts', 'onec_doc_guid', 'ack_note'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime', 'delivered_at' => 'datetime'];
    }
}
