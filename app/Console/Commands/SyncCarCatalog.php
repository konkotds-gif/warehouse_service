<?php

namespace App\Console\Commands;

use App\Domain\Cars\CarCatalogSync;
use Illuminate\Console\Command;

class SyncCarCatalog extends Command
{
    protected $signature = 'cars:sync {--force : Загрузить полностью, не сверяя версию}';

    protected $description = 'Загрузить справочник марок и моделей авто из системы записи';

    public function handle(CarCatalogSync $sync): int
    {
        $result = $sync->run((bool) $this->option('force'));
        $result['status'] === 'error' ? $this->error($result['message']) : $this->info($result['message']);

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
