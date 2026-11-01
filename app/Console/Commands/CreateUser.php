<?php

namespace App\Console\Commands;

use App\Domain\Users\Role;
use App\Models\Location;
use App\Models\User;
use Illuminate\Console\Command;

class CreateUser extends Command
{
    protected $signature = 'user:create {login} {name} {--role=admin} {--location= : Код локации} {--password=}';

    protected $description = 'Создать пользователя (например, первого администратора)';

    public function handle(): int
    {
        $role = Role::tryFrom($this->option('role'));
        if (! $role) {
            $this->error('Роль: '.implode(', ', array_map(fn ($r) => $r->value, Role::cases())));

            return self::FAILURE;
        }
        $location = $this->option('location') ? Location::where('code', $this->option('location'))->first() : null;
        $password = $this->option('password') ?: $this->secret('Пароль');
        if (strlen((string) $password) < 8) {
            $this->error('Пароль — не короче 8 символов');

            return self::FAILURE;
        }
        $user = User::updateOrCreate(['login' => $this->argument('login')], [
            'name' => $this->argument('name'),
            'password' => $password,
            'role' => $role,
            'location_id' => $location?->id,
            'is_active' => true,
        ]);
        $this->info("Пользователь {$user->login} ({$role->label()}) сохранён");

        return self::SUCCESS;
    }
}
