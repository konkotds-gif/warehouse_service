<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Для staging/обучения: php artisan db:seed (демо-данные).
     * В проде справочники заводятся через интерфейс/1С, первый администратор — php artisan user:create.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
