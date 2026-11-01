<?php

namespace App\Domain\Warehouse;

enum TaskType: string
{
    case Place = 'place';
    case Pick = 'pick';
    case PrepareIssue = 'prepare_issue';
    case Inventory = 'inventory';
    case Discrepancy = 'discrepancy';

    public function label(): string
    {
        return match ($this) {
            self::Place => 'Разместить',
            self::Pick => 'Отобрать к выдаче',
            self::PrepareIssue => 'Подготовить к выдаче',
            self::Inventory => 'Инвентаризация',
            self::Discrepancy => 'Разобрать расхождение',
        };
    }
}
