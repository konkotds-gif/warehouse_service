<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Отправленное SMS и его статус доставки в SMSC. */
class SmsMessage extends Model
{
    protected $fillable = ['wheel_set_id', 'kind', 'phone', 'driver', 'provider_id', 'status', 'status_error', 'status_at',
        'checked_at', 'final', 'error'];

    /** Статусы SMSC (smsc.ru/api/http/status_messages/statuses). */
    public const STATUSES = [
        -3 => 'сообщение не найдено в SMSC',
        -2 => 'остановлено',
        -1 => 'ожидает отправки',
        0 => 'передано оператору',
        1 => 'доставлено',
        2 => 'прочитано',
        3 => 'просрочено (не доставлено за время жизни)',
        4 => 'доставлено, ссылка открыта',
        20 => 'невозможно доставить',
        22 => 'неверный номер',
        23 => 'запрещено (спам-фильтр, дубликат или чёрный список)',
        24 => 'недостаточно средств',
        25 => 'номер недоступен',
    ];

    /** Статусы, после которых опрос не нужен. */
    public const FINAL = [-3, -2, 1, 2, 3, 4, 20, 22, 23, 24, 25];

    public const DELIVERED = [1, 2, 4];

    public const KINDS = [
        'code' => 'код подтверждения договора',
        'ready' => 'колёса готовы к выдаче',
        'test' => 'тестовое',
        'other' => 'уведомление',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'status_error' => 'integer',
            'status_at' => 'datetime',
            'checked_at' => 'datetime',
            'final' => 'boolean',
        ];
    }

    public function wheelSet(): BelongsTo
    {
        return $this->belongsTo(WheelSet::class);
    }

    public static function statusLabel(?int $status): string
    {
        return $status === null ? 'нет данных' : (self::STATUSES[$status] ?? "статус {$status}");
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function isDelivered(): bool
    {
        return in_array($this->status, self::DELIVERED, true);
    }
}
