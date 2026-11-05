<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** Настройки платформы «ключ → значение» (раздел «Настройки» в администрировании). */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::query()->whereKey($key)->value('value');

        return $value === null ? $default : json_decode($value, true);
    }

    public static function put(string $key, mixed $value): void
    {
        if ($value === null) {
            static::query()->whereKey($key)->delete();

            return;
        }
        static::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    /** Секрет (ключ API) хранится зашифрованным ключом приложения (APP_KEY). */
    public static function getSecret(string $key): ?string
    {
        $value = static::get($key);
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null; // APP_KEY сменили — ключ нужно ввести заново
        }
    }

    public static function putSecret(string $key, ?string $plain): void
    {
        static::put($key, $plain === null || $plain === '' ? null : Crypt::encryptString($plain));
    }
}
