<?php

namespace App\Domain\Notifications;

use App\Domain\Storage\SetMover;
use App\Models\Movement;
use App\Models\SmsMessage;
use App\Models\WheelSet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * SMS клиентам. Если в «Настройках» указан SMSC — отправка через него, иначе драйвер из .env (SMS_DRIVER=log — запись в лог).
 * Каждое SMS пишется в журнал sms_messages; SMS по комплекту — ещё и в историю движений (отправлено, статус доставки).
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
        ), $set, 'ready');
    }

    /** Через SMSC или в лог. */
    public function driver(): string
    {
        return $this->smsc->configured() ? 'smsc' : (string) config('storage.sms_driver', 'log');
    }

    /** @return array{ok: bool, id: ?string, error: ?string, message: ?SmsMessage} */
    public function send(string $phone, string $text, ?WheelSet $set = null, string $kind = 'other'): array
    {
        if (app()->bound('sms.sent')) {
            app('sms.sent')->push(['phone' => $phone, 'text' => $text]);
        }
        $driver = $this->driver();
        if ($driver === 'smsc') {
            $result = $this->smsc->send($phone, $text);
        } elseif ($driver === 'log') {
            Log::info('SMS', ['phone' => $phone, 'text' => $text]);
            $result = ['ok' => true, 'id' => null, 'error' => null];
        } else {
            Log::warning('SMS driver not configured', ['driver' => $driver]);
            $result = ['ok' => false, 'id' => null, 'error' => 'SMS-шлюз не настроен'];
        }

        $message = SmsMessage::create([
            'wheel_set_id' => $set?->id,
            'kind' => $kind,
            'phone' => $phone,
            'driver' => $driver,
            'provider_id' => $result['id'],
            'error' => $result['ok'] ? null : mb_substr((string) $result['error'], 0, 255),
            // без SMSC статус доставки узнать неоткуда
            'final' => ! $result['ok'] || $driver !== 'smsc' || ! $result['id'],
        ]);
        if ($set) {
            $note = $result['ok']
                ? sprintf('SMS «%s» на %s%s', $message->kindLabel(), $phone,
                    $driver === 'smsc' ? ($result['id'] ? " · SMSC id {$result['id']}" : '') : ' · без шлюза, записано в лог')
                : sprintf('SMS «%s» на %s не отправлено: %s', $message->kindLabel(), $phone, $result['error']);
            self::logMovement($set, $result['ok'] ? 'sms_sent' : 'sms_failed', $note);
        }

        return $result + ['message' => $message];
    }

    /** Запросить статус доставки в SMSC и записать изменение в журнал и историю движений. */
    public function refreshStatus(SmsMessage $message): SmsMessage
    {
        if ($message->final || ! $message->provider_id || ! $this->smsc->configured()) {
            return $message;
        }
        $res = $this->smsc->status($message->phone, $message->provider_id);
        $message->checked_at = now();
        if (! $res['ok']) {
            $message->save();
            Log::warning('SMSC: статус не получен', ['sms' => $message->id, 'error' => $res['error']]);

            return $message;
        }
        $changed = $res['status'] !== $message->status;
        $message->fill([
            'status' => $res['status'],
            'status_error' => $res['err'] ?: null,
            'status_at' => $res['at'] ? Carbon::createFromTimestamp($res['at']) : now(),
            // окончательный статус, либо SMSC так и не доставил за сутки
            'final' => in_array($res['status'], SmsMessage::FINAL, true) || $message->created_at->lt(now()->subDay()),
        ])->save();

        if ($changed && $message->final && $message->wheelSet) {
            self::logMovement($message->wheelSet, $message->isDelivered() ? 'sms_delivered' : 'sms_undelivered', sprintf(
                'SMS «%s» на %s: %s%s', $message->kindLabel(), $message->phone, SmsMessage::statusLabel($message->status),
                $message->status_error ? " (код ошибки {$message->status_error})" : ''
            ), $message->status_at);
        }

        return $message;
    }

    /** Запись в историю движений комплекта, не меняющая статус и адрес. */
    public static function logMovement(WheelSet $set, string $action, string $note, ?Carbon $at = null): Movement
    {
        return Movement::create([
            'wheel_set_id' => $set->id,
            'at' => $at ?? now(),
            'user_id' => Auth::id(),
            'action' => $action,
            'device' => SetMover::device(),
            'note' => mb_substr($note, 0, 255),
        ]);
    }
}
