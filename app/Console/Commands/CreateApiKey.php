<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class CreateApiKey extends Command
{
    protected $signature = 'onec:key {name : Название учётки, например "1С УТ"} {--ips= : Разрешённые IP через запятую} {--scope=onec : Область ключа: onec (1С) или booking (онлайн-запись)}';

    protected $description = 'Создать ключ API для 1С или онлайн-записи (ключ показывается один раз)';

    public function handle(): int
    {
        $scope = (string) $this->option('scope');
        if (! array_key_exists($scope, ApiKey::SCOPES)) {
            $this->error('Область ключа: '.implode(' или ', array_keys(ApiKey::SCOPES)));

            return self::FAILURE;
        }
        [$key, $plain] = ApiKey::generate($this->argument('name'), $this->option('ips') ?: null, $scope);
        $this->info("Ключ #{$key->id} «{$key->name}» создан. Сохраните его — повторно он не показывается:");
        $this->line($plain);

        return self::SUCCESS;
    }
}
