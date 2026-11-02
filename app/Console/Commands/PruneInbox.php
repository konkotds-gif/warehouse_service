<?php

namespace App\Console\Commands;

use App\Models\InboxRecord;
use Illuminate\Console\Command;

class PruneInbox extends Command
{
    protected $signature = 'onec:prune-inbox';

    protected $description = 'Удалить записи integration_inbox старше срока хранения';

    public function handle(): int
    {
        $days = (int) config('storage.onec.inbox_retention_days', 90);
        $deleted = InboxRecord::where('processed_at', '<', now()->subDays($days))->delete();
        $this->info("Удалено: {$deleted}");

        return self::SUCCESS;
    }
}
