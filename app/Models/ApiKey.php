<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/** Ключ сервисной учётки 1С. В БД хранится только SHA-256 хэш. */
class ApiKey extends Model
{
    protected $fillable = ['name', 'key_hash', 'allowed_ips', 'scopes', 'is_active', 'last_used_at'];

    /** Области ключа: onec — обмен с 1С, booking — онлайн-запись. */
    public const SCOPES = ['onec' => '1С', 'booking' => 'Онлайн-запись'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_used_at' => 'datetime', 'scopes' => 'array'];
    }

    /** @return array{0: ApiKey, 1: string} модель и открытый ключ (показывается один раз) */
    public static function generate(string $name, ?string $allowedIps = null, string $scope = 'onec'): array
    {
        $plain = 'wk_'.Str::random(40);
        $key = static::create(['name' => $name, 'key_hash' => hash('sha256', $plain), 'allowed_ips' => $allowedIps, 'scopes' => [$scope]]);

        return [$key, $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        return static::where('key_hash', hash('sha256', $plain))->where('is_active', true)->first();
    }

    /** Ключ без областей (созданный до их появления) считается ключом 1С. */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?: ['onec'], true);
    }

    public function allowsIp(?string $ip): bool
    {
        if (blank($this->allowed_ips)) {
            return true;
        }

        // Через запятую: отдельные адреса или подсети (10.0.0.0/24)
        return $ip !== null && IpUtils::checkIp($ip, self::ipList($this->allowed_ips));
    }

    /** @return list<string> */
    public static function ipList(?string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $value))));
    }
}
