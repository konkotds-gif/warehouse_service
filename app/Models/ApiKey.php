<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Ключ сервисной учётки 1С. В БД хранится только SHA-256 хэш. */
class ApiKey extends Model
{
    protected $fillable = ['name', 'key_hash', 'allowed_ips', 'is_active', 'last_used_at'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_used_at' => 'datetime'];
    }

    /** @return array{0: ApiKey, 1: string} модель и открытый ключ (показывается один раз) */
    public static function generate(string $name, ?string $allowedIps = null): array
    {
        $plain = 'wk_'.Str::random(40);
        $key = static::create(['name' => $name, 'key_hash' => hash('sha256', $plain), 'allowed_ips' => $allowedIps]);

        return [$key, $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        return static::where('key_hash', hash('sha256', $plain))->where('is_active', true)->first();
    }

    public function allowsIp(?string $ip): bool
    {
        if (blank($this->allowed_ips)) {
            return true;
        }
        $list = array_map('trim', explode(',', $this->allowed_ips));

        return $ip !== null && in_array($ip, $list, true);
    }
}
