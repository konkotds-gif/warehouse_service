<?php

namespace App\Domain\Notifications;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMS-шлюз SMSC (https://smsc.ru/api/http/): send.php и balance.php, ответ в JSON (fmt=3).
 * Авторизация на выбор: логин + пароль (login/psw) или API-ключ (apikey, создаётся на smsc.ru/passwords).
 * Задаётся в «Настройках» (пароль и ключ — зашифрованными APP_KEY), запасной вариант — .env.
 */
class SmscGateway
{
    public const LOGIN = 'sms.smsc.login';

    public const PASSWORD = 'sms.smsc.password';

    public const SENDER = 'sms.smsc.sender';

    public const AUTH = 'sms.smsc.auth';

    public const API_KEY = 'sms.smsc.apikey';

    public const AUTH_PASSWORD = 'password';

    public const AUTH_APIKEY = 'apikey';

    /** Тексты ошибок SMSC (error_code) для сотрудников. */
    private const ERRORS = [
        1 => 'ошибка в параметрах запроса',
        2 => 'неверный логин/пароль или API-ключ SMSC (или IP сервера не в списке разрешённых)',
        3 => 'недостаточно средств на счёте SMSC',
        4 => 'IP сервера временно заблокирован SMSC из-за частых ошибок',
        5 => 'неверный формат даты',
        6 => 'сообщение запрещено (по тексту или имени отправителя)',
        7 => 'неверный формат номера телефона',
        8 => 'сообщение на этот номер не может быть доставлено',
        9 => 'слишком частые одинаковые запросы, повторите через минуту',
    ];

    public function login(): ?string
    {
        return Setting::get(self::LOGIN) ?: (config('services.smsc.login') ?: null);
    }

    public function password(): ?string
    {
        return Setting::getSecret(self::PASSWORD) ?: (config('services.smsc.password') ?: null);
    }

    public function apiKey(): ?string
    {
        return Setting::getSecret(self::API_KEY) ?: (config('services.smsc.apikey') ?: null);
    }

    /** Способ авторизации: из настроек; если не выбран — по ключу, когда есть только он. */
    public function auth(): string
    {
        $auth = Setting::get(self::AUTH);
        if (in_array($auth, [self::AUTH_PASSWORD, self::AUTH_APIKEY], true)) {
            return $auth;
        }

        return $this->apiKey() && ! ($this->login() && $this->password()) ? self::AUTH_APIKEY : self::AUTH_PASSWORD;
    }

    public function sender(): ?string
    {
        return Setting::get(self::SENDER) ?: (config('services.smsc.sender') ?: null);
    }

    public function configured(): bool
    {
        return $this->auth() === self::AUTH_APIKEY
            ? filled($this->apiKey())
            : filled($this->login()) && filled($this->password());
    }

    /** @return array<string, string> */
    private function credentials(): array
    {
        return $this->auth() === self::AUTH_APIKEY
            ? ['apikey' => (string) $this->apiKey()]
            : ['login' => (string) $this->login(), 'psw' => (string) $this->password()];
    }

    /**
     * Отправить SMS. Телефон — в любом виде (+7…, 8…), SMSC нужен международный формат цифрами.
     *
     * @return array{ok: bool, id: ?string, error: ?string}
     */
    public function send(string $phone, string $text): array
    {
        $params = ['phones' => self::phone($phone), 'mes' => $text];
        if ($sender = $this->sender()) {
            $params['sender'] = $sender;
        }
        $res = $this->call('send.php', $params);
        if (! $res['ok']) {
            return ['ok' => false, 'id' => null, 'error' => $res['error']];
        }

        return ['ok' => true, 'id' => isset($res['data']['id']) ? (string) $res['data']['id'] : null, 'error' => null];
    }

    /** @return array{ok: bool, balance: ?string, currency: ?string, error: ?string} */
    public function balance(): array
    {
        $res = $this->call('balance.php', []);
        if (! $res['ok']) {
            return ['ok' => false, 'balance' => null, 'currency' => null, 'error' => $res['error']];
        }

        return ['ok' => true, 'balance' => (string) ($res['data']['balance'] ?? ''), 'currency' => $res['data']['currency'] ?? null, 'error' => null];
    }

    /**
     * Статус доставки сообщения (status.php). Коды статусов — App\Models\SmsMessage::STATUSES.
     *
     * @return array{ok: bool, status: ?int, err: ?int, at: ?int, error: ?string}
     */
    public function status(string $phone, string $id): array
    {
        $res = $this->call('status.php', ['phone' => self::phone($phone), 'id' => $id]);
        if (! $res['ok'] || ! isset($res['data']['status'])) {
            return ['ok' => false, 'status' => null, 'err' => null, 'at' => null, 'error' => $res['error'] ?? 'SMSC не вернул статус'];
        }
        $d = $res['data'];

        return [
            'ok' => true,
            'status' => (int) $d['status'],
            'err' => isset($d['err']) && $d['err'] !== '' ? (int) $d['err'] : null,
            'at' => isset($d['last_timestamp']) && (int) $d['last_timestamp'] > 0 ? (int) $d['last_timestamp'] : null,
            'error' => null,
        ];
    }

    /** Номер для SMSC: 79123456789 (российские 10 цифр дополняются семёркой). */
    public static function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7'.$digits;
        }

        return $digits;
    }

    /** @return array{ok: bool, data: array, error: ?string} */
    private function call(string $method, array $params): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'data' => [], 'error' => $this->auth() === self::AUTH_APIKEY
                ? 'SMSC не настроен: укажите API-ключ в «Настройках»'
                : 'SMSC не настроен: укажите логин и пароль в «Настройках»'];
        }
        try {
            $response = $this->http()->post(rtrim((string) config('services.smsc.url'), '/').'/'.$method, $params + $this->credentials() + [
                'fmt' => 3,
                'charset' => 'utf-8',
            ]);
        } catch (ConnectionException $e) {
            Log::warning('SMSC недоступен', ['method' => $method, 'error' => $e->getMessage()]);

            return ['ok' => false, 'data' => [], 'error' => 'SMSC недоступен: '.$e->getMessage()];
        }
        $data = $response->json();
        if (! $response->successful() || ! is_array($data)) {
            return ['ok' => false, 'data' => [], 'error' => 'SMSC ответил HTTP '.$response->status()];
        }
        if (isset($data['error']) || isset($data['error_code'])) {
            $code = (int) ($data['error_code'] ?? 0);
            $message = self::ERRORS[$code] ?? (string) ($data['error'] ?? 'неизвестная ошибка');
            Log::warning('SMSC ошибка', ['method' => $method, 'code' => $code, 'error' => $data['error'] ?? null]);

            return ['ok' => false, 'data' => $data, 'error' => "SMSC: {$message}".($code ? " (код {$code})" : '')];
        }

        return ['ok' => true, 'data' => $data, 'error' => null];
    }

    private function http(): PendingRequest
    {
        return Http::asForm()->acceptJson()->timeout(15)->connectTimeout(5);
    }
}
