<?php

namespace App\Domain\Ldap;

use LDAP\Connection;

/** Подключение через расширение php-ldap (ldap_*). */
class NativeLdapConnection implements LdapConnection
{
    private ?Connection $link = null;

    public function connect(string $host, LdapConfig $config): void
    {
        if (! LdapConfig::extensionLoaded()) {
            throw new LdapException('На сервере не установлено PHP-расширение ldap (php8.4-ldap) — см. docs/INSTALL.md.');
        }
        $this->close();
        $scheme = $config->encryption === 'ldaps' ? 'ldaps' : 'ldap';
        $uri = $scheme.'://'.$host.':'.$config->port;

        $caFile = $this->caFile($config->caCert);
        $require = $config->verifyCert ? LDAP_OPT_X_TLS_DEMAND : LDAP_OPT_X_TLS_NEVER;
        // Опции TLS libldap берёт из глобальных настроек при создании соединения — задаём их до ldap_connect
        @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, $require);
        if ($caFile) {
            @ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $caFile);
        }

        $link = @ldap_connect($uri);
        if (! $link) {
            throw new LdapException("Некорректный адрес контроллера домена: {$uri}");
        }
        ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($link, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($link, LDAP_OPT_NETWORK_TIMEOUT, $config->timeout);
        ldap_set_option($link, LDAP_OPT_TIMELIMIT, $config->timeout * 2);

        if ($config->encryption === 'starttls' && ! @ldap_start_tls($link)) {
            $error = $this->errorOf($link);
            throw new LdapException("{$host}: не удалось включить StartTLS ({$error}). Проверьте сертификат контроллера домена.");
        }
        $this->link = $link;
    }

    public function bind(string $user, string $password): bool
    {
        if ($password === '') {
            return false; // пустой пароль в AD = анонимная привязка, которая «успешна» — не допускаем
        }
        $ok = @ldap_bind($this->link(), $user, $password);
        if (! $ok && ldap_errno($this->link()) !== 49) { // 49 — invalidCredentials
            throw new LdapException($this->lastError());
        }

        return $ok;
    }

    public function search(string $baseDn, string $filter, array $attributes, int $sizeLimit = 0): array
    {
        $result = @ldap_search($this->link(), $baseDn, $filter, $attributes, 0, $sizeLimit);
        if ($result === false) {
            throw new LdapException('Ошибка поиска в AD: '.$this->lastError());
        }
        $entries = @ldap_get_entries($this->link(), $result);
        if ($entries === false) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
            $entry = $entries[$i];
            $row = ['dn' => [(string) $entry['dn']]];
            for ($j = 0; $j < ($entry['count'] ?? 0); $j++) {
                $name = strtolower($entry[$j]);
                $values = $entry[$entry[$j]] ?? $entry[$name] ?? [];
                unset($values['count']);
                $row[$name] = array_values(array_map('strval', $values));
            }
            $out[] = $row;
        }

        return $out;
    }

    public function lastError(): string
    {
        return $this->link ? $this->errorOf($this->link) : 'нет подключения';
    }

    public function close(): void
    {
        if ($this->link) {
            @ldap_unbind($this->link);
            $this->link = null;
        }
    }

    private function link(): Connection
    {
        return $this->link ?? throw new LdapException('Нет подключения к AD');
    }

    private function errorOf(Connection $link): string
    {
        $error = ldap_error($link);
        $diag = null;
        @ldap_get_option($link, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag);

        return $diag ? "{$error}: {$diag}" : $error;
    }

    /** Корневой сертификат CA из настроек — во временный файл (libldap принимает только путь). */
    private function caFile(?string $pem): ?string
    {
        if (! $pem) {
            return null;
        }
        $path = storage_path('app/ldap/ca-'.substr(sha1($pem), 0, 12).'.pem');
        if (! is_file($path)) {
            @mkdir(dirname($path), 0750, true);
            file_put_contents($path, $pem);
        }

        return $path;
    }
}
