<?php

namespace Database\Factories;

use App\Domain\Users\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'login' => 'u'.Str::lower(Str::random(8)),
            'name' => fake()->name(),
            'email' => null,
            'password' => static::$password ??= 'password',
            'role' => Role::Admin,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }
}
