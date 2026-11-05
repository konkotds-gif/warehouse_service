<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Notifications\SmscGateway;
use App\Domain\Storage\ContractConfirmation;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** «Настройки → SMS (SMSC)»: подключение к smsc.ru и подтверждение договоров кодом из SMS. */
class SmsSettingsController extends Controller
{
    public static function props(): array
    {
        $smsc = app(SmscGateway::class);
        $password = $smsc->password();
        $key = $smsc->apiKey();

        return [
            'auth' => $smsc->auth(),
            'apikey_set' => (bool) $key,
            'apikey_mask' => $key ? mb_substr($key, 0, 4).'…'.mb_substr($key, -4) : null,
            'apikey_from_env' => $key && ! Setting::getSecret(SmscGateway::API_KEY),
            'login' => $smsc->login(),
            'sender' => $smsc->sender(),
            'password_set' => (bool) $password,
            'from_env' => $password && ! Setting::getSecret(SmscGateway::PASSWORD),
            'configured' => $smsc->configured(),
            'confirm_contracts' => (bool) Setting::get(ContractConfirmation::ENABLED, true),
            'confirm_text' => ContractConfirmation::text(),
            'default_text' => ContractConfirmation::DEFAULT_TEXT,
            'confirm_close' => (bool) Setting::get(ContractConfirmation::CLOSE_ENABLED, true),
            'close_text' => ContractConfirmation::closeText(),
            'default_close_text' => ContractConfirmation::DEFAULT_CLOSE_TEXT,
            'ttl_minutes' => ContractConfirmation::TTL_MINUTES,
            'max_attempts' => ContractConfirmation::MAX_ATTEMPTS,
        ];
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auth' => ['required', 'in:password,apikey'],
            'login' => ['nullable', 'string', 'max:64'],
            'apikey' => ['nullable', 'string', 'max:128'],
            'remove_apikey' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'max:128'],
            'remove_password' => ['nullable', 'boolean'],
            'sender' => ['nullable', 'string', 'max:15'],
            'confirm_contracts' => ['required', 'boolean'],
            'confirm_text' => ['required', 'string', 'max:300', function ($attr, $value, $fail) {
                if (! str_contains($value, '{code}')) {
                    $fail('В тексте SMS должен быть {code} — на это место подставится код');
                }
            }],
            'confirm_close' => ['sometimes', 'boolean'],
            'close_text' => ['sometimes', 'required', 'string', 'max:300', function ($attr, $value, $fail) {
                if (! str_contains($value, '{code}')) {
                    $fail('В тексте SMS должен быть {code} — на это место подставится код');
                }
            }],
        ], ['sender.max' => 'Имя отправителя — до 11 букв или 15 цифр, как зарегистрировано в SMSC']);

        Setting::put(SmscGateway::AUTH, $data['auth']);
        if ($request->boolean('remove_apikey')) {
            Setting::putSecret(SmscGateway::API_KEY, null);
        } elseif (filled($data['apikey'] ?? null)) {
            Setting::putSecret(SmscGateway::API_KEY, trim($data['apikey']));
        }
        Setting::put(SmscGateway::LOGIN, filled($data['login'] ?? null) ? trim($data['login']) : null);
        Setting::put(SmscGateway::SENDER, filled($data['sender'] ?? null) ? trim($data['sender']) : null);
        if ($request->boolean('remove_password')) {
            Setting::putSecret(SmscGateway::PASSWORD, null);
        } elseif (filled($data['password'] ?? null)) {
            Setting::putSecret(SmscGateway::PASSWORD, $data['password']);
        }
        Setting::put(ContractConfirmation::ENABLED, (bool) $data['confirm_contracts']);
        Setting::put(ContractConfirmation::TEXT, trim($data['confirm_text']) === ContractConfirmation::DEFAULT_TEXT ? null : trim($data['confirm_text']));
        if (array_key_exists('confirm_close', $data)) {
            Setting::put(ContractConfirmation::CLOSE_ENABLED, (bool) $data['confirm_close']);
        }
        if (array_key_exists('close_text', $data)) {
            Setting::put(ContractConfirmation::CLOSE_TEXT, trim($data['close_text']) === ContractConfirmation::DEFAULT_CLOSE_TEXT ? null : trim($data['close_text']));
        }

        return back()->with('success', 'Настройки SMS сохранены');
    }

    /** Проверка: баланс SMSC, а если указан телефон — ещё и тестовое SMS. */
    public function test(Request $request, SmscGateway $smsc): RedirectResponse
    {
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:32']]);
        $balance = $smsc->balance();
        if (! $balance['ok']) {
            return back()->with('error', $balance['error']);
        }
        $message = 'Подключение к SMSC работает, баланс: '.$balance['balance'].' '.($balance['currency'] ?? 'руб.');
        if (filled($data['phone'] ?? null)) {
            $sent = $smsc->send($data['phone'], 'Проверка SMS платформы хранения колёс. Диски Мира');
            if (! $sent['ok']) {
                return back()->with('error', $message.'. Тестовое SMS не отправлено: '.$sent['error']);
            }
            $message .= '. Тестовое SMS отправлено на '.$data['phone'];
        }

        return back()->with('success', $message);
    }
}
