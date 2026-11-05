<?php

namespace App\Domain\Storage;

/**
 * Статус комплекта — конечный автомат (план, раздел 4).
 *
 * ПРИНЯТ (буфер магазина) → В_ПУТИ → НА_СКЛАДЕ (ожидает размещения) → РАЗМЕЩЁН
 * → ЗАКАЗАН_К_ВЫДАЧЕ → В_ПУТИ → ГОТОВ_К_ВЫДАЧЕ → ВЫДАН.
 */
enum SetStatus: string
{
    case Accepted = 'accepted';       // принят, лежит в буфере магазина М<код>
    case InTransit = 'in_transit';    // в пути TR-<номер>
    case Received = 'received';       // привезли на склад, ждёт размещения
    case Placed = 'placed';           // в ячейке XX_YY_ZZ
    case Ordered = 'ordered';         // заказан к выдаче
    case Ready = 'ready';             // готов к выдаче в буфере магазина выдачи
    case Issued = 'issued';           // выдан клиенту
    case Disposed = 'disposed';       // утилизирован
    case Lost = 'lost';               // утерян / расхождение по инвентаризации

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Принят',
            self::InTransit => 'В пути',
            self::Received => 'На складе, не размещён',
            self::Placed => 'Размещён',
            self::Ordered => 'Заказан к выдаче',
            self::Ready => 'Готов к выдаче',
            self::Issued => 'Выдан',
            self::Disposed => 'Утилизирован',
            self::Lost => 'Утерян / расхождение',
        };
    }

    /** Цвет бейджа (техдок, раздел 7.2). */
    public function color(): string
    {
        return match ($this) {
            self::Accepted => 'ink',
            self::InTransit => 'blue',
            self::Received => 'blue',
            self::Placed => 'green',
            self::Ordered => 'amber',
            self::Ready => 'navy',
            self::Issued => 'gray',
            self::Disposed => 'gray',
            self::Lost => 'red',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Accepted => [self::InTransit, self::Placed, self::Ordered, self::Ready, self::Disposed, self::Lost],
            self::InTransit => [self::Accepted, self::Received, self::Ready, self::Lost],
            self::Received => [self::Placed, self::InTransit, self::Ordered, self::Disposed, self::Lost],
            self::Placed => [self::Placed, self::Ordered, self::InTransit, self::Disposed, self::Lost],
            self::Ordered => [self::InTransit, self::Placed, self::Ready, self::Received, self::Accepted, self::Lost],
            self::Ready => [self::Issued, self::InTransit, self::Accepted, self::Placed, self::Received, self::Lost],
            self::Issued => [],
            self::Disposed => [],
            self::Lost => [self::Placed, self::Received, self::Accepted, self::Disposed],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /** Комплект физически находится на хранении (не выдан и не списан). */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Issued, self::Disposed], true);
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isActive())));
    }

    /** @return array<string, array{label: string, color: string}> */
    public static function dictionary(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = ['label' => $case->label(), 'color' => $case->color()];
        }

        return $out;
    }
}
