<?php

namespace Tests\Support;

use App\Domain\Ldap\LdapConfig;
use App\Domain\Ldap\LdapConnection;
use App\Domain\Ldap\LdapException;

/** Имитация Active Directory для тестов: учётки, группы (с вложенностью), недоступные контроллеры. */
class FakeLdap implements LdapConnection
{
    public const SERVICE = 'svc-wh@corp.local';

    public const SERVICE_PASSWORD = 'svc-secret';

    /** @var array<string, array{dn: string, guid: string, password: string, name: string, mail: ?string, uac: int, expires: string, groups: list<string>}> */
    public array $users = [];

    /** @var array<string, array{cn: string, parents: list<string>}> DN группы → имя и группы, в которые она вложена */
    public array $groups = [];

    /** @var list<string> */
    public array $down = [];

    /** @var list<string> */
    public array $connectedTo = [];

    private ?string $host = null;

    private string $error = '';

    public function addGroup(string $cn, array $parents = []): string
    {
        $dn = "CN={$cn},OU=Groups,DC=corp,DC=local";
        $this->groups[$dn] = ['cn' => $cn, 'parents' => $parents];

        return $dn;
    }

    public function addUser(string $login, string $password, array $groups = [], array $extra = []): string
    {
        $guid = random_bytes(16);
        $this->users[strtolower($login)] = array_merge([
            'dn' => "CN={$login},OU=Staff,DC=corp,DC=local", 'guid' => $guid, 'password' => $password, 'login' => $login,
            'name' => ucfirst($login).' Тестовый', 'mail' => "{$login}@corp.local", 'uac' => 512, 'expires' => '0', 'groups' => $groups,
        ], $extra);

        return $guid;
    }

    public function connect(string $host, LdapConfig $config): void
    {
        if (in_array($host, $this->down, true)) {
            throw new LdapException("Can't contact LDAP server");
        }
        $this->host = $host;
        $this->connectedTo[] = $host;
    }

    public function bind(string $user, string $password): bool
    {
        if ($password !== '' && $user === self::SERVICE && $password === self::SERVICE_PASSWORD) {
            return true;
        }
        foreach ($this->users as $u) {
            if (strcasecmp($u['dn'], $user) === 0 && $password !== '' && hash_equals($u['password'], $password)) {
                return true;
            }
        }
        $this->error = 'Invalid credentials: 80090308: LdapErr: DSID-0C09044E, comment: AcceptSecurityContext error, data 52e, v4563';

        return false;
    }

    public function search(string $baseDn, string $filter, array $attributes, int $sizeLimit = 0): array
    {
        if (! $this->host) {
            throw new LdapException('not connected');
        }
        if (preg_match('/sAMAccountName=([^)]*)\)/', $filter, $m) && str_contains($filter, 'objectClass=user')) {
            $u = $this->users[strtolower($this->unescape($m[1]))] ?? null;

            return $u ? [$this->userRow($u)] : [];
        }
        if (preg_match('/objectGUID=([^)]*)\)/', $filter, $m)) {
            $bin = hex2bin(str_replace('\\', '', $m[1]));
            foreach ($this->users as $u) {
                if ($u['guid'] === $bin) {
                    return [$this->userRow($u)];
                }
            }

            return [];
        }
        if (preg_match('/member:1\.2\.840\.113556\.1\.4\.1941:=([^)]*)\)/', $filter, $m)) {
            $dn = $this->unescape($m[1]);
            $user = collect($this->users)->first(fn ($u) => strcasecmp($u['dn'], $dn) === 0);

            return array_map(fn ($g) => ['dn' => [$g], 'cn' => [$this->groups[$g]['cn']]], $this->expand($user['groups'] ?? []));
        }
        if (str_contains($filter, 'objectClass=group')) {
            $q = preg_match('/cn=\*([^*]*)\*/', $filter, $m) ? mb_strtolower($this->unescape($m[1])) : '';
            $rows = [];
            foreach ($this->groups as $dn => $g) {
                if ($q === '' || str_contains(mb_strtolower($g['cn']), $q)) {
                    $rows[] = ['dn' => [$dn], 'cn' => [$g['cn']]];
                }
            }

            return $sizeLimit ? array_slice($rows, 0, $sizeLimit) : $rows;
        }

        return [];
    }

    public function lastError(): string
    {
        return $this->error;
    }

    public function close(): void
    {
        $this->host = null;
    }

    /** Группы с учётом вложенности (как LDAP_MATCHING_RULE_IN_CHAIN). */
    private function expand(array $dns): array
    {
        $all = [];
        while ($dns) {
            $dn = array_shift($dns);
            if (isset($all[$dn]) || ! isset($this->groups[$dn])) {
                continue;
            }
            $all[$dn] = true;
            array_push($dns, ...$this->groups[$dn]['parents']);
        }

        return array_keys($all);
    }

    private function userRow(array $u): array
    {
        return ['dn' => [$u['dn']], 'samaccountname' => [$u['login']], 'displayname' => [$u['name']], 'mail' => $u['mail'] ? [$u['mail']] : [],
            'objectguid' => [$u['guid']], 'useraccountcontrol' => [(string) $u['uac']], 'accountexpires' => [$u['expires']]];
    }

    private function unescape(string $v): string
    {
        return preg_replace_callback('/\\\\([0-9a-f]{2})/i', fn ($m) => chr(hexdec($m[1])), $v);
    }
}
