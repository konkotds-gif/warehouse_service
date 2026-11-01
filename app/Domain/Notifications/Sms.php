<?php

namespace App\Domain\Notifications;

use App\Models\WheelSet;
use Illuminate\Support\Facades\Log;

/**
 * SMS клиентам (этап 4). Драйвер по умолчанию — запись в лог; подключение шлюза — в send().
 */
class Sms
{
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

    public function send(string $phone, string $text): void
    {
        $driver = config('storage.sms_driver', 'log');
        if (app()->bound('sms.sent')) {
            app('sms.sent')->push(['phone' => $phone, 'text' => $text]);
        }
        if ($driver === 'log') {
            Log::info('SMS', ['phone' => $phone, 'text' => $text]);

            return;
        }
        // Место для подключения SMS-шлюза (sms.ru, SMSC и т.п.) по SMS_API_KEY.
        Log::warning('SMS driver not configured', ['driver' => $driver]);
    }
}
