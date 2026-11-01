<?php

namespace App\Models;

use App\Domain\Support\Normalizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['client_id', 'plate', 'make', 'model'];

    public function setPlateAttribute(?string $value): void
    {
        $this->attributes['plate'] = Normalizer::plate($value);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function garageSets(): HasMany
    {
        return $this->hasMany(GarageSet::class);
    }

    public function title(): string
    {
        return trim(implode(' ', array_filter([$this->make, $this->model, $this->plate])));
    }
}
