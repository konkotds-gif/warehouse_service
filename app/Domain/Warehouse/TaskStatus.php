<?php

namespace App\Domain\Warehouse;

enum TaskStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
