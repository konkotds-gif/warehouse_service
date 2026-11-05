<?php

namespace App\Domain\Ldap;

use App\Models\LdapGroupRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Права доменного пользователя по его группам AD и создание/обновление пользователя платформы.
 * Роль и магазин назначаются заново при каждом входе и при фоновой сверке (ldap:sync).
 */
class LdapAccess
{
    /** Старшинство ролей: при нескольких группах берётся старшая. */
    public const RANK = [
        'admin' => 5, 'store_manager' => 4, 'storekeeper' => 3, 'seller' => 2, 'driver' => 1,
    ];

    /** Подходящее сопоставление для групп пользователя или null — доступа к платформе нет. */
    public function resolve(LdapUser $user): ?LdapGroupRole
    {
        $dns = $user->groupDns();
        if ($dns === []) {
            return null;
        }

        return LdapGroupRole::query()->orderBy('id')->get()
            ->filter(fn (LdapGroupRole $m) => in_array(mb_strtolower($m->group_dn), $dns, true))
            ->sortBy([
                fn ($a, $b) => self::RANK[$b->role->value] <=> self::RANK[$a->role->value],
                fn ($a, $b) => ($b->location_id !== null) <=> ($a->location_id !== null),
            ])
            ->first();
    }

    /** Создать или обновить пользователя платформы по учётке AD. */
    public function provision(LdapUser $ldap, LdapGroupRole $mapping): User
    {
        $user = User::where('ldap_guid', $ldap->guid)->first()
            ?? User::where('auth_source', 'ldap')->where('login', $ldap->login)->first()
            ?? new User(['is_active' => true, 'password' => Hash::make(Str::random(40))]);

        $user->forceFill([
            'login' => $ldap->login,
            'name' => $ldap->name ?: $ldap->login,
            'email' => $ldap->email,
            'auth_source' => 'ldap',
            'ldap_guid' => $ldap->guid,
            'ldap_dn' => $ldap->dn,
            'ldap_synced_at' => now(),
            'ldap_denied_at' => null,
            'role' => $mapping->role,
        ]);
        if ($mapping->location_id) {
            $user->location_id = $mapping->location_id;
        }
        $user->save();

        return $user;
    }

    /** Закрыть доступ пользователю со стороны AD (не трогая ручную блокировку is_active). */
    public function deny(User $user): void
    {
        if (! $user->ldap_denied_at) {
            $user->forceFill(['ldap_denied_at' => now(), 'ldap_synced_at' => now()])->save();
        }
    }
}
