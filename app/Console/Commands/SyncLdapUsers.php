<?php

namespace App\Console\Commands;

use App\Domain\Ldap\LdapAccess;
use App\Domain\Ldap\LdapConfig;
use App\Domain\Ldap\LdapDirectory;
use App\Domain\Ldap\LdapException;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Сверка доменных пользователей с AD: роль и магазин по группам, закрытие доступа уволенным,
 * отключённым и убранным из групп (их сеансы завершаются при следующем запросе).
 * Если AD недоступен — ничего не меняет.
 */
class SyncLdapUsers extends Command
{
    protected $signature = 'ldap:sync';

    protected $description = 'Сверить доменных пользователей с Active Directory (роли по группам, отключённые учётки)';

    public function handle(LdapDirectory $ldap, LdapAccess $access): int
    {
        if (! $ldap->enabled()) {
            $this->info('Вход через AD выключен — сверка пропущена.');

            return self::SUCCESS;
        }
        $users = User::where('auth_source', 'ldap')->whereNotNull('ldap_guid')->get();
        try {
            $found = $ldap->lookupMany($users->pluck('ldap_guid')->all());
        } catch (LdapException $e) {
            $this->record('error', $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $updated = $denied = 0;
        foreach ($users as $user) {
            $entry = $found[$user->ldap_guid] ?? null;
            $mapping = $entry && ! $entry->disabled && ! $entry->expired ? $access->resolve($entry) : null;
            if ($mapping) {
                $access->provision($entry, $mapping);
                $updated++;
            } else {
                $access->deny($user);
                $denied++;
            }
        }
        $message = "Проверено доменных пользователей: {$users->count()}, с доступом: {$updated}, доступ закрыт: {$denied}.";
        $this->record('ok', $message);
        $this->info($message);

        return self::SUCCESS;
    }

    private function record(string $status, string $message): void
    {
        Setting::put(LdapConfig::LAST_SYNC, ['at' => now()->toIso8601String(), 'status' => $status, 'message' => $message]);
    }
}
