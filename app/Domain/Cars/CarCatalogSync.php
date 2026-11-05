<?php

namespace App\Domain\Cars;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Загрузка справочника марок и моделей авто по API системы записи (zapis.diskimira.com).
 * Адрес и ключ — из «Настройки → Справочник авто» (ключ хранится зашифрованным),
 * если там пусто — из .env (CAR_CATALOG_API_URL / CAR_CATALOG_API_KEY).
 *
 * Схема (по инструкции API): GET /catalog?include_inactive=1 с If-None-Match: <версия>;
 * 304 — ничего не делаем; 200 — upsert марок и моделей по внешнему id в одной транзакции,
 * отсутствующие в ответе помечаем неактивными, сохраняем meta.version.
 */
class CarCatalogSync
{
    public const URL = 'cars.api_url';

    public const KEY = 'cars.api_key';

    public const VERSION = 'cars.version';

    public const LAST = 'cars.last_sync';

    public const LAST_SUCCESS = 'cars.last_success_at';

    public const DEFAULT_URL = 'https://zapis.diskimira.com/api/v1/vehicles';

    public function url(): string
    {
        return rtrim((string) (Setting::get(self::URL) ?: config('services.car_catalog.url') ?: self::DEFAULT_URL), '/');
    }

    public function key(): ?string
    {
        return Setting::getSecret(self::KEY) ?: (config('services.car_catalog.key') ?: null);
    }

    public function configured(): bool
    {
        return (bool) $this->key();
    }

    /** Проверка подключения: список марок без моделей. */
    public function test(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Не указан ключ API.'];
        }
        try {
            $response = $this->request()->get($this->url().'/makes');
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'Нет связи с '.$this->url().': '.$e->getMessage()];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'message' => $this->httpError($response)];
        }

        return ['ok' => true, 'message' => 'Подключение работает. Марок в справочнике: '.count($response->json('data') ?? []).'.'];
    }

    /**
     * @return array{status: string, message: string, makes?: int, models?: int}
     *                                                                           status: updated | not_modified | error | skipped
     */
    public function run(bool $force = false): array
    {
        if (! $this->configured()) {
            return ['status' => 'skipped', 'message' => 'Ключ API не указан — синхронизация пропущена.'];
        }

        try {
            $result = $this->sync($force);
        } catch (\Throwable $e) {
            report($e);
            $result = ['status' => 'error', 'message' => $e instanceof SyncError ? $e->getMessage() : 'Ошибка синхронизации: '.$e->getMessage()];
        }

        Setting::put(self::LAST, $result + ['at' => now()->toIso8601String()]);
        if ($result['status'] !== 'error') {
            Setting::put(self::LAST_SUCCESS, now()->toIso8601String());
        } else {
            Log::warning('cars:sync — '.$result['message']);
        }

        return $result;
    }

    private function sync(bool $force): array
    {
        $version = $force ? null : Setting::get(self::VERSION);

        try {
            $response = $this->request()
                ->timeout(60)
                ->retry(3, 2000, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)), throw: false)
                ->withHeaders($version ? ['If-None-Match' => '"'.$version.'"'] : [])
                ->get($this->url().'/catalog', ['include_inactive' => 1]);
        } catch (ConnectionException $e) {
            throw new SyncError('Нет связи с '.$this->url().': '.$e->getMessage());
        }

        if ($response->status() === 304) {
            return ['status' => 'not_modified', 'message' => 'Справочник не менялся.'];
        }
        if (! $response->successful()) {
            throw new SyncError($this->httpError($response));
        }

        $data = $response->json('data');
        if (! is_array($data) || ! $data) {
            // Пустой ответ не применяем: иначе все марки стали бы неактивными.
            throw new SyncError('API вернул пустой справочник — изменения не применены.');
        }

        $now = now();
        $makes = $models = [];
        foreach ($data as $make) {
            if (! isset($make['id'], $make['slug'], $make['name'])) {
                throw new SyncError('Неожиданный формат ответа API (у марки нет id/slug/name).');
            }
            $makes[] = [
                'id' => (int) $make['id'], 'slug' => mb_substr($make['slug'], 0, 64), 'name' => mb_substr(trim($make['name']), 0, 100),
                'aliases' => json_encode(array_values(array_filter((array) ($make['aliases'] ?? []), 'is_string')), JSON_UNESCAPED_UNICODE),
                'is_popular' => (bool) ($make['is_popular'] ?? false), 'is_active' => (bool) ($make['is_active'] ?? true),
                'created_at' => $now, 'updated_at' => $now,
            ];
            foreach ($make['models'] ?? [] as $model) {
                if (! isset($model['id'], $model['name'])) {
                    continue;
                }
                $models[] = [
                    'id' => (int) $model['id'], 'car_make_id' => (int) $make['id'], 'slug' => mb_substr((string) ($model['slug'] ?? ''), 0, 100),
                    'name' => mb_substr(trim($model['name']), 0, 100), 'is_active' => (bool) ($model['is_active'] ?? true),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($makes, $models) {
            foreach (array_chunk($makes, 500) as $chunk) {
                DB::table('car_makes')->upsert($chunk, ['id'], ['slug', 'name', 'aliases', 'is_popular', 'is_active', 'updated_at']);
            }
            foreach (array_chunk($models, 500) as $chunk) {
                DB::table('car_models')->upsert($chunk, ['id'], ['car_make_id', 'slug', 'name', 'is_active', 'updated_at']);
            }
            // Удалённые в справочнике — скрываем у себя (данные авто клиентов не трогаем).
            DB::table('car_makes')->whereNotIn('id', array_column($makes, 'id'))->update(['is_active' => false]);
            DB::table('car_models')->whereNotIn('id', array_column($models, 'id') ?: [0])->update(['is_active' => false]);
        });

        Setting::put(self::VERSION, $response->json('meta.version') ?: trim((string) $response->header('ETag'), '"W/') ?: null);

        return [
            'status' => 'updated',
            'message' => 'Загружено марок: '.count($makes).', моделей: '.count($models).'.',
            'makes' => count($makes), 'models' => count($models),
        ];
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) $this->key())->acceptJson()->timeout(20)->connectTimeout(10);
    }

    private function httpError(Response $response): string
    {
        $text = $response->json('message') ?: $response->reason();

        return match ($response->status()) {
            401 => 'Ключ API не принят (401): неверный, отозван или истёк.',
            404 => 'Адрес API не найден (404) — проверьте URL.',
            429 => 'Превышен лимит запросов (429), повторите через '.($response->header('Retry-After') ?: '60').' с.',
            default => 'API ответил HTTP '.$response->status().($text ? ': '.$text : ''),
        };
    }
}
