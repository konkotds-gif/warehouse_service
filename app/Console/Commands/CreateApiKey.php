<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class CreateApiKey extends Command
{
    protected $signature = 'onec:key {name : Название учётки, например "1С УТ"} {--ips= : Разрешённые IP через запятую}';

    protected $description = 'Создать ключ API для сервисной учётки 1С (ключ показывается один раз)';

    public function handle(): int
    {
        [$key, $plain] = ApiKey::generate($this->argument('name'), $this->option('ips') ?: null);
        $this->info("Ключ #{$key->id} «{$key->name}» создан. Сохраните его — повторно он не показывается:");
        $this->line($plain);

        return self::SUCCESS;
    }
}
