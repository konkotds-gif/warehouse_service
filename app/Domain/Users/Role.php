<?php

namespace App\Domain\Users;

/**
 * Роли пользователей (план, раздел 7). Права — простая матрица «роль → действия»;
 * проверяются через Gate (см. AppServiceProvider).
 */
enum Role: string
{
    case Seller = 'seller';
    case Storekeeper = 'storekeeper';
    case Driver = 'driver';
    case StoreManager = 'store_manager';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Seller => 'Продавец магазина',
            self::Storekeeper => 'Кладовщик',
            self::Driver => 'Водитель',
            self::StoreManager => 'Руководитель магазина',
            self::Admin => 'Администратор',
        };
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return match ($this) {
            self::Seller => ['accept', 'issue', 'order-issue', 'receive-transfer', 'search', 'clients', 'print', 'transfers'],
            self::Storekeeper => ['place', 'pick', 'transfers', 'ship-transfer', 'receive-transfer', 'inventory', 'search', 'print', 'tasks'],
            self::Driver => ['ship-transfer', 'receive-transfer', 'search', 'transfers'],
            self::StoreManager => ['accept', 'issue', 'order-issue', 'receive-transfer', 'ship-transfer', 'transfers', 'search', 'clients',
                'print', 'reports', 'fix', 'tasks', 'place', 'pick', 'inventory', 'export-pd'],
            self::Admin => ['*'],
        };
    }

    public function can(string $ability): bool
    {
        $abilities = $this->abilities();

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }
}
