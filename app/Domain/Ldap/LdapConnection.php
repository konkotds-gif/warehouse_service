<?php

namespace App\Domain\Ldap;

/**
 * Низкоуровневое подключение к каталогу. В работе — NativeLdapConnection (расширение php-ldap),
 * в тестах подменяется фейком через контейнер.
 */
interface LdapConnection
{
    /** Подключиться к одному контроллеру домена; при ошибке — LdapException. */
    public function connect(string $host, LdapConfig $config): void;

    /** Простая привязка. false — неверные учётные данные (подробности в lastError()). */
    public function bind(string $user, string $password): bool;

    /**
     * Поиск по поддереву. Атрибуты в ответе — в нижнем регистре, значения — всегда списки строк.
     *
     * @param  list<string>  $attributes
     * @return list<array<string, list<string>>> каждая запись дополнена ключом 'dn' => [DN]
     */
    public function search(string $baseDn, string $filter, array $attributes, int $sizeLimit = 0): array;

    public function lastError(): string;

    public function close(): void;
}
