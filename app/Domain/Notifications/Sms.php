<?php

namespace App\Domain\Notifications;

use App\Models\WheelSet;
use Illuminate\Support\Facades\Log;

/**
 * SMS клиентам. Если в «Настройках» указан SMSC — отправка через него, иначе драйвер из .env (SMS_DRIVER=log — запись в лог).
 */
class Sms
{
    public function __construct(private readonly SmscGateway $smsc) {}

    public function setReady(WheelSet $set): void
    {
        $client = $set->contract?->client;
        if (! $client || ! $client->consent_sms || ! $client->phone) {
            return;
        }
        $store = $set->issueStore ?? $set->location;
        $this->send($client->phone, sprintf(
            'Ваши колёса по договору %s готовы к выдаче: %s. Диски Мира',
            $set->contract->number,
            $store?->name ?? ''
        ));
    }

    /** Через SMSC или в лог. */
    public function driver(): string
    {
        return $this->smsc->configured() ? 'smsc' : (string) config('storage.sms_driver', 'log');
    }

    /** @return array{ok: bool, id: ?string, error: ?string} */
    public function send(string $phone, string $text): array
    {
        if (app()->bound('sms.sent')) {
            app('sms.sent')->push(['phone' => $phone, 'text' => $text]);
        }
        $driver = $this->driver();
        if ($driver === 'smsc') {
            return $this->smsc->send($phone, $text);
        }
        if ($driver === 'log') {
            Log::info('SMS', ['phone' => $phone, 'text' => $text]);

            return ['ok' => true, 'id' => null, 'error' => null];
        }
        Log::warning('SMS driver not configured', ['driver' => $driver]);

        return ['ok' => false, 'id' => null, 'error' => 'SMS-шлюз не настроен'];
    }
}
