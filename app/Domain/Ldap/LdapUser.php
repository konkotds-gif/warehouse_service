<?php

namespace App\Domain\Ldap;

/** Учётная запись из AD вместе с её группами (с учётом вложенности). */
final class LdapUser
{
    /** @param list<array{dn: string, name: string}> $groups */
    public function __construct(
        public string $dn,
        public string $guid,
        public string $login,
        public string $name,
        public ?string $email,
        public bool $disabled,
        public bool $expired,
        public array $groups = [],
    ) {}

    /** @return list<string> DN групп в нижнем регистре — для сравнения с сопоставлениями */
    public function groupDns(): array
    {
        return array_map(fn ($g) => mb_strtolower($g['dn']), $this->groups);
    }
}
