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

    /** Марку и модель привязываем к справочнику авто (если нашлись) и приводим к написанию из справочника. */
    protected static function booted(): void
    {
        static::saving(function (self $v) {
            if (! $v->isDirty(['make', 'model']) && $v->exists) {
                return;
            }
            $make = CarMake::findByName($v->make);
            $v->car_make_id = $make?->id;
            $v->car_model_id = null;
            if (! $make) {
                return;
            }
            $v->make = $make->name;
            $model = $make->models()->where('is_active', true)->where('name', trim((string) $v->model))->first();
            if ($model) {
                $v->car_model_id = $model->id;
                $v->model = $model->name;
            }
        });
    }

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
