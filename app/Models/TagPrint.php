<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagPrint extends Model
{
    public $timestamps = false;

    protected $fillable = ['wheel_set_id', 'copies', 'reason', 'format', 'printed_by', 'printed_at'];

    protected function casts(): array
    {
        return ['printed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
