<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Запись истории владельцев автомобиля. */
class VehicleOwnerChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['vehicle_id', 'from_client_id', 'to_client_id', 'sets_moved', 'user_id', 'note', 'at'];

    protected function casts(): array
    {
        return ['at' => 'datetime', 'sets_moved' => 'boolean'];
    }

    public function fromClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'from_client_id')->withTrashed();
    }

    public function toClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'to_client_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
