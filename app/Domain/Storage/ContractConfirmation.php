<?php

namespace App\Domain\Storage;

use App\Domain\Notifications\Sms;
use App\Domain\Notifications\SmscGateway;
use App\Domain\Support\DomainError;
use App\Models\ContractSmsCode;
use App\Models\Setting;
use App\Models\StorageContract;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Подтверждение договора хранения кодом из SMS: при оформлении договора клиенту уходит код,
 * сотрудник вводит его в карточке договора. Код живёт 10 минут, 5 попыток ввода, повтор отправки — не чаще раза в минуту.
 */
final class ContractConfirmation
{
    public const ENABLED = 'sms.confirm_contracts';

    public const TEXT = 'sms.confirm_text';

    public const DEFAULT_TEXT = 'Код {code} - подтверждение договора хранения № {number}. Диски Мира';

    public const CODE_LENGTH = 4;

    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public const MAX_SENDS_PER_DAY = 5;

    public function __construct(private readonly Sms $sms, private readonly SmscGateway $smsc) {}

    /** Включено в настройках и подключён SMSC. */
    public function enabled(): bool
    {
        return (bool) Setting::get(self::ENABLED, true) && $this->smsc->configured();
    }

    public static function text(): string
    {
        return Setting::get(self::TEXT) ?: self::DEFAULT_TEXT;
    }

    /**
     * После оформления договора: отправить код, если подтверждение включено и у клиента есть телефон.
     * Ошибка шлюза договор не отменяет — код можно отправить повторно из карточки.
     *
     * @return array{sent: bool, message: string}|null null — подтверждение не требуется
     */
    public function start(StorageContract $contract, ?User $user = null): ?array
    {
        if (! $this->enabled() || $contract->sms_confirmed_at) {
            return null;
        }
        if (! $contract->client?->phone) {
            return ['sent' => false, 'message' => 'У клиента не указан телефон — код подтверждения не отправлен'];
        }
        try {
            $code = $this->send($contract, $user);
        } catch (DomainError $e) {
            return ['sent' => false, 'message' => $e->getMessage()];
        }

        return $code->isDelivered()
            ? ['sent' => true, 'message' => 'Клиенту отправлен код подтверждения на '.$code->phone]
            : ['sent' => false, 'message' => 'Код не отправлен: '.$code->error];
    }

    /** Отправить (или повторно отправить) код. Неудачная отправка тоже пишется в журнал с текстом ошибки. */
    public function send(StorageContract $contract, ?User $user = null): ContractSmsCode
    {
        if (! $this->enabled()) {
            throw new DomainError('Подтверждение по SMS выключено или SMSC не настроен');
        }
        if ($contract->sms_confirmed_at) {
            throw new DomainError('Договор уже подтверждён');
        }
        $phone = $contract->client?->phone;
        if (! $phone) {
            throw DomainError::invalid('У клиента не указан телефон', 'code');
        }
        $last = $contract->smsCodes()->whereNull('error')->first();
        if ($last && $last->created_at->diffInSeconds(now()) < self::RESEND_SECONDS) {
            $wait = self::RESEND_SECONDS - (int) $last->created_at->diffInSeconds(now());
            throw new DomainError("Повторно отправить код можно через {$wait} сек.", 429);
        }
        $today = $contract->smsCodes()->whereNull('error')->where('created_at', '>=', now()->subDay())->count();
        if ($today >= self::MAX_SENDS_PER_DAY) {
            throw new DomainError('Превышен лимит отправки кодов по договору ('.self::MAX_SENDS_PER_DAY.' за сутки)', 429);
        }

        $plain = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $text = strtr(self::text(), ['{code}' => $plain, '{number}' => $contract->number]);
        $result = $this->sms->send($phone, $text);

        return $contract->smsCodes()->create([
            'phone' => $phone,
            'code_hash' => self::hash($contract, $plain),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'provider_id' => $result['id'],
            'error' => $result['ok'] ? null : mb_substr((string) $result['error'], 0, 255),
            'sent_by' => $user?->id,
        ]);
    }

    /** Проверить введённый код и отметить договор подтверждённым. */
    public function verify(StorageContract $contract, string $code, ?User $user = null): StorageContract
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';

        // Счётчик неверных попыток должен сохраниться, поэтому ошибку бросаем уже после транзакции
        $result = DB::transaction(function () use ($contract, $code, $user) {
            $contract = StorageContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($contract->sms_confirmed_at) {
                return $contract;
            }
            /** @var ContractSmsCode|null $sent */
            $sent = $contract->smsCodes()->whereNull('error')->whereNull('used_at')->lockForUpdate()->first();
            if (! $sent) {
                return 'Код ещё не отправлялся — нажмите «Отправить код»';
            }
            if ($sent->expires_at->isPast()) {
                return 'Срок действия кода истёк — отправьте новый';
            }
            if ($sent->attempts >= self::MAX_ATTEMPTS) {
                return 'Исчерпаны попытки ввода — отправьте новый код';
            }
            $sent->attempts++;
            if ($code === '' || ! hash_equals($sent->code_hash, self::hash($contract, $code))) {
                $sent->save();
                $left = self::MAX_ATTEMPTS - $sent->attempts;

                return $left > 0 ? "Неверный код. Осталось попыток: {$left}" : 'Неверный код. Попытки исчерпаны — отправьте новый код';
            }
            $sent->used_at = now();
            $sent->save();
            $contract->forceFill([
                'sms_confirmed_at' => now(),
                'sms_confirmed_phone' => $sent->phone,
                'sms_confirmed_by' => $user?->id,
            ])->save();

            return $contract;
        });
        if (is_string($result)) {
            throw DomainError::invalid($result, 'code');
        }

        return $result;
    }

    /** Состояние подтверждения для карточки договора. */
    public function status(StorageContract $contract): array
    {
        $codes = $contract->smsCodes()->with('sender')->limit(10)->get();
        $last = $codes->first(fn (ContractSmsCode $c) => $c->error === null);

        return [
            'enabled' => $this->enabled(),
            'confirmed_at' => $contract->sms_confirmed_at?->toIso8601String(),
            'confirmed_phone' => $contract->sms_confirmed_phone,
            'confirmed_by' => $contract->smsConfirmedBy?->name,
            'phone' => $contract->client?->phone,
            'last_sent_at' => $last?->created_at->toIso8601String(),
            'expires_at' => $last && ! $last->used_at ? $last->expires_at->toIso8601String() : null,
            'resend_in' => $last ? max(0, self::RESEND_SECONDS - (int) $last->created_at->diffInSeconds(now())) : 0,
            'code_length' => self::CODE_LENGTH,
            'log' => $codes->map(fn (ContractSmsCode $c) => [
                'at' => $c->created_at->toIso8601String(),
                'phone' => $c->phone,
                'error' => $c->error,
                'user' => $c->sender?->name,
                'used' => $c->used_at !== null,
            ])->all(),
        ];
    }

    private static function hash(StorageContract $contract, string $code): string
    {
        return hash_hmac('sha256', $contract->id.'|'.$code, (string) config('app.key'));
    }
}
