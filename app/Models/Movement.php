<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Журнал движений: только добавление (в БД дополнительно стоят триггеры). */
class Movement extends Model
{
    public $timestamps = false;

    protected $fillable = ['wheel_set_id', 'at', 'user_id', 'action', 'from_address', 'to_address',
        'from_status', 'to_status', 'transfer_id', 'qty', 'device', 'note'];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Журнал движений нельзя изменять'));
        static::deleting(fn () => throw new LogicException('Журнал движений нельзя удалять'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wheelSet(): BelongsTo
    {
        return $this->belongsTo(WheelSet::class);
    }

    public static function actionLabel(string $action): string
    {
        return [
            'accept' => 'Приём на хранение',
            'place' => 'Размещение',
            'move' => 'Перекладка в другую ячейку',
            'ship' => 'Отгрузка',
            'receive' => 'Приёмка',
            'order' => 'Заказ к выдаче',
            'order_cancel' => 'Отмена заказа',
            'ready' => 'Готов к выдаче',
            'issue' => 'Выдача клиенту',
            'dispose' => 'Утилизация',
            'lost' => 'Утерян / расхождение',
            'found' => 'Найден',
            'qty_mismatch' => 'Расхождение по количеству',
            'reprint' => 'Перепечатка бирок',
            'edit' => 'Исправление данных',
        ][$action] ?? $action;
    }
}
