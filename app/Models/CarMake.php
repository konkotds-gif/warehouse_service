<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Марка авто из справочника системы записи (id внешний, не меняется). */
class CarMake extends Model
{
    public $incrementing = false;

    protected $fillable = ['id', 'slug', 'name', 'aliases', 'is_popular', 'is_active'];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'is_popular' => 'boolean', 'is_active' => 'boolean'];
    }

    public function models(): HasMany
    {
        return $this->hasMany(CarModel::class);
    }

    /** Для селекта: популярные сначала, внутри — по алфавиту. */
    public static function forSelect(): array
    {
        return static::where('is_active', true)->orderByDesc('is_popular')->orderBy('name')
            ->get(['name', 'aliases', 'is_popular'])
            ->map(fn (self $m) => ['value' => $m->name, 'aliases' => array_values($m->aliases ?? []), 'popular' => $m->is_popular])
            ->all();
    }

    /** Поиск марки по названию, коду или алиасу (без учёта регистра). */
    public static function findByName(?string $name): ?self
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $lower = mb_strtolower($name);

        return static::where('is_active', true)->where(fn ($q) => $q->where('name', $name)->orWhere('slug', $lower))->first()
            ?? static::where('is_active', true)->whereNotNull('aliases')->get()
                ->first(fn (self $m) => in_array($lower, array_map('mb_strtolower', $m->aliases ?? []), true));
    }
}
