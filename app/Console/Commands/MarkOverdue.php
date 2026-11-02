<?php

namespace App\Console\Commands;

use App\Domain\Storage\ContractService;
use Illuminate\Console\Command;

class MarkOverdue extends Command
{
    protected $signature = 'storage:mark-overdue';

    protected $description = 'Пометить просроченные договоры хранения и отправить событие set.overdue в 1С';

    public function handle(ContractService $service): int
    {
        $count = $service->markOverdue();
        $this->info("Помечено просроченными: {$count}");

        return self::SUCCESS;
    }
}
