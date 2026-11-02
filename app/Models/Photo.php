<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Photo extends Model
{
    public $timestamps = false;

    protected $fillable = ['wheel_set_id', 'path', 'taken_by', 'taken_at'];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime'];
    }
}
