<?php

namespace App\Domain\Ldap;

use App\Models\Setting;

/**
 * Настройки подключения к Active Directory («Настройки → Вход через AD»).
 * Пароль сервисной учётки хранится отдельно и зашифрованным (Setting::putSecret).
 */
final class LdapConfig
{
    public const KEY = 'ldap.config';

    public const PASSWORD = 'ldap.bind_password';

    public const LAST_SYNC = 'ldap.last_sync';

    public const ENCRYPTIONS = ['ldaps', 'starttls', 'none'];

    /** @param list<string> $hosts */
    public function __construct(
        public bool $enabled = false,
        public array $hosts = [],
        public int $port = 636,
        public string $encryption = 'ldaps',
        public bool $verifyCert = true,
        public ?string $caCert = null,
        public string $baseDn = '',
        public string $bindUser = '',
        public ?string $bindPassword = null,
        public string $domain = '',
        public int $timeout = 5,
    ) {}

    public static function load(): self
    {
        $c = (array) Setting::get(self::KEY, []);

        return new self(
            enabled: (bool) ($c['enabled'] ?? false),
            hosts: array_values(array_filter(array_map('trim', (array) ($c['hosts'] ?? [])))),
            port: (int) ($c['port'] ?? 636),
            encryption: in_array($c['encryption'] ?? null, self::ENCRYPTIONS, true) ? $c['encryption'] : 'ldaps',
            verifyCert: (bool) ($c['verify_cert'] ?? true),
            caCert: ($c['ca_cert'] ?? null) ?: null,
            baseDn: (string) ($c['base_dn'] ?? ''),
            bindUser: (string) ($c['bind_user'] ?? ''),
            bindPassword: Setting::getSecret(self::PASSWORD),
            domain: (string) ($c['domain'] ?? ''),
            timeout: max(1, min(30, (int) ($c['timeout'] ?? 5))),
        );
    }

    /** Сохранить всё, кроме пароля (он меняется отдельно). */
    public function save(): void
    {
        Setting::put(self::KEY, [
            'enabled' => $this->enabled,
            'hosts' => $this->hosts,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'verify_cert' => $this->verifyCert,
            'ca_cert' => $this->caCert,
            'base_dn' => $this->baseDn,
            'bind_user' => $this->bindUser,
            'domain' => $this->domain,
            'timeout' => $this->timeout,
        ]);
    }

    /** Достаточно ли заполнено, чтобы подключаться (флаг «включено» не учитывается). */
    public function complete(): bool
    {
        return $this->hosts !== [] && $this->baseDn !== '' && $this->bindUser !== '' && filled($this->bindPassword);
    }

    public static function extensionLoaded(): bool
    {
        return extension_loaded('ldap');
    }
}
