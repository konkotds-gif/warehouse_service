<?php

namespace App\Domain\Ldap;

use Closure;

/**
 * Работа с Active Directory: поиск учётки и её групп сервисной учётной записью,
 * проверка пароля привязкой от имени пользователя, поиск групп для сопоставления.
 *
 * Контроллеры домена перебираются по порядку: если первый недоступен, пробуем следующий.
 */
class LdapDirectory
{
    private const USER_ATTRS = ['samaccountname', 'displayname', 'cn', 'mail', 'objectguid', 'useraccountcontrol', 'accountexpires'];

    private const UAC_DISABLED = 0x2;

    /** Подкоды AD в сообщении об ошибке привязки (data XXX) */
    private const BIND_REASONS = [
        '52e' => 'Неверный логин или пароль',
        '530' => 'Вход в это время запрещён политикой домена',
        '531' => 'Вход с этого компьютера запрещён политикой домена',
        '532' => 'Срок действия пароля истёк — смените пароль при входе в Windows',
        '533' => 'Учётная запись отключена в домене',
        '701' => 'Срок действия учётной записи истёк',
        '773' => 'Нужно сменить пароль — войдите в Windows и задайте новый',
        '775' => 'Учётная запись заблокирована в домене после неудачных попыток входа',
    ];

    public function __construct(private LdapConnection $connection, private ?LdapConfig $config = null) {}

    public function config(): LdapConfig
    {
        return $this->config ??= LdapConfig::load();
    }

    /** Вход через AD включён и настроен. */
    public function enabled(): bool
    {
        return $this->config()->enabled && $this->config()->complete();
    }

    /** «DOMAIN\ivanov» и «ivanov@domain.local» → «ivanov». */
    public static function normalizeLogin(string $login): string
    {
        $login = trim($login);
        if (str_contains($login, '\\')) {
            $login = substr($login, strrpos($login, '\\') + 1);
        }
        if (str_contains($login, '@')) {
            $login = substr($login, 0, strpos($login, '@'));
        }

        return $login;
    }

    /**
     * Проверить логин и пароль в AD.
     *
     * @throws LdapAuthFailed пароль неверен, учётка отключена/заблокирована/не найдена
     * @throws LdapException AD недоступен или неверно настроен
     */
    public function authenticate(string $login, string $password): LdapUser
    {
        $login = self::normalizeLogin($login);
        if ($login === '' || $password === '') {
            throw new LdapAuthFailed('Неверный логин или пароль');
        }

        return $this->withService(function () use ($login, $password) {
            $user = $this->findUser($login) ?? throw new LdapAuthFailed('Неверный логин или пароль');
            if ($user->disabled) {
                throw new LdapAuthFailed(self::BIND_REASONS['533']);
            }
            if ($user->expired) {
                throw new LdapAuthFailed(self::BIND_REASONS['701']);
            }
            if (! $this->connection->bind($user->dn, $password)) {
                throw new LdapAuthFailed($this->bindReason($this->connection->lastError()));
            }
            $this->bindService(); // группы читаем снова от сервисной учётки
            $user->groups = $this->groupsOf($user->dn);

            return $user;
        });
    }

    /** Найти учётку по логину (без проверки пароля) — для проверки настроек. */
    public function lookup(string $login): ?LdapUser
    {
        return $this->withService(function () use ($login) {
            $user = $this->findUser(self::normalizeLogin($login));
            if ($user) {
                $user->groups = $this->groupsOf($user->dn);
            }

            return $user;
        });
    }

    /**
     * Найти учётки по objectGUID — для фоновой сверки.
     *
     * @param  list<string>  $guids
     * @return array<string, LdapUser> guid → учётка; отсутствующих в AD нет в результате
     */
    public function lookupMany(array $guids): array
    {
        return $this->withService(function () use ($guids) {
            $found = [];
            foreach ($guids as $guid) {
                $rows = $this->connection->search($this->config()->baseDn,
                    '(&(objectCategory=person)(objectClass=user)(objectGUID='.self::guidFilter($guid).'))', self::USER_ATTRS, 1);
                if ($rows) {
                    $user = $this->toUser($rows[0]);
                    $user->groups = $this->groupsOf($user->dn);
                    $found[$guid] = $user;
                }
            }

            return $found;
        });
    }

    /**
     * Группы для выбора в сопоставлении.
     *
     * @return list<array{dn: string, name: string, description: ?string}>
     */
    public function searchGroups(string $query): array
    {
        $q = self::escape(trim($query));
        $filter = $q === ''
            ? '(objectClass=group)'
            : "(&(objectClass=group)(|(cn=*{$q}*)(sAMAccountName=*{$q}*)(description=*{$q}*)))";

        return $this->withService(function () use ($filter) {
            $rows = $this->connection->search($this->config()->baseDn, $filter, ['cn', 'description'], 50);
            $groups = array_map(fn ($r) => ['dn' => $r['dn'][0], 'name' => $r['cn'][0] ?? $r['dn'][0], 'description' => $r['description'][0] ?? null], $rows);
            usort($groups, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            return $groups;
        });
    }

    /** Проверка подключения и сервисной учётки. */
    public function test(): string
    {
        return $this->withService(function (string $host) {
            $groups = $this->connection->search($this->config()->baseDn, '(objectClass=group)', ['cn'], 5);

            return "Подключение к {$host} работает, сервисная учётная запись принята, base DN доступен"
                .($groups ? '.' : ', но групп в нём не найдено — проверьте base DN.');
        });
    }

    /**
     * Подключиться к первому доступному контроллеру, войти сервисной учёткой и выполнить $fn.
     *
     * @template T
     *
     * @param  Closure(string $host): T  $fn
     * @return T
     */
    private function withService(Closure $fn): mixed
    {
        $config = $this->config();
        if (! $config->complete()) {
            throw new LdapException('Подключение к AD не настроено: укажите контроллер домена, base DN и сервисную учётную запись.');
        }
        $errors = [];
        foreach ($config->hosts as $host) {
            try {
                $this->connection->connect($host, $config);
                $this->bindService();
            } catch (LdapException $e) {
                $errors[] = $host.': '.$e->getMessage();
                $this->connection->close();

                continue;
            }
            try {
                return $fn($host);
            } finally {
                $this->connection->close();
            }
        }

        throw new LdapException('Контроллер домена недоступен. '.implode('; ', $errors));
    }

    private function bindService(): void
    {
        $config = $this->config();
        if (! $this->connection->bind($config->bindUser, (string) $config->bindPassword)) {
            throw new LdapException('Сервисная учётная запись не принята ('.$this->bindReason($this->connection->lastError()).').');
        }
    }

    private function findUser(string $login): ?LdapUser
    {
        $filter = '(&(objectCategory=person)(objectClass=user)(sAMAccountName='.self::escape($login).'))';
        $rows = $this->connection->search($this->config()->baseDn, $filter, self::USER_ATTRS, 2);

        return count($rows) === 1 ? $this->toUser($rows[0]) : null;
    }

    /**
     * Все группы пользователя, включая вложенные (LDAP_MATCHING_RULE_IN_CHAIN).
     * Основная группа («Пользователи домена») сюда не попадает — для сопоставления её не использовать.
     *
     * @return list<array{dn: string, name: string}>
     */
    private function groupsOf(string $userDn): array
    {
        $filter = '(&(objectClass=group)(member:1.2.840.113556.1.4.1941:='.self::escape($userDn).'))';
        $rows = $this->connection->search($this->config()->baseDn, $filter, ['cn']);

        return array_map(fn ($r) => ['dn' => $r['dn'][0], 'name' => $r['cn'][0] ?? $r['dn'][0]], $rows);
    }

    /** @param array<string, list<string>> $row */
    private function toUser(array $row): LdapUser
    {
        $uac = (int) ($row['useraccountcontrol'][0] ?? 0);
        $expires = $row['accountexpires'][0] ?? '0';

        return new LdapUser(
            dn: $row['dn'][0],
            guid: self::guidToString($row['objectguid'][0] ?? ''),
            login: $row['samaccountname'][0] ?? '',
            name: $row['displayname'][0] ?? $row['cn'][0] ?? ($row['samaccountname'][0] ?? ''),
            email: $row['mail'][0] ?? null,
            disabled: (bool) ($uac & self::UAC_DISABLED),
            expired: self::isExpired($expires),
        );
    }

    private function bindReason(string $error): string
    {
        if (preg_match('/data ([0-9a-f]{3})/i', $error, $m) && isset(self::BIND_REASONS[strtolower($m[1])])) {
            return self::BIND_REASONS[strtolower($m[1])];
        }

        return 'Неверный логин или пароль';
    }

    /** accountExpires — FILETIME (100 нс с 1601 г.); 0 и 2^63-1 — «никогда». */
    public static function isExpired(string $value): bool
    {
        if ($value === '' || $value === '0' || $value === '9223372036854775807') {
            return false;
        }
        $unix = intdiv((int) $value, 10_000_000) - 11_644_473_600;

        return $unix < time();
    }

    /** objectGUID (16 байт) → «xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx» в порядке, как показывает Windows. */
    public static function guidToString(string $binary): string
    {
        if (strlen($binary) !== 16) {
            return $binary; // уже строка (или пусто)
        }
        $hex = bin2hex($binary);

        return implode('-', [
            implode('', array_reverse(str_split(substr($hex, 0, 8), 2))),
            implode('', array_reverse(str_split(substr($hex, 8, 4), 2))),
            implode('', array_reverse(str_split(substr($hex, 12, 4), 2))),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }

    /** Строковый GUID → экранированные байты для фильтра (objectGUID=\xx\xx…). */
    public static function guidFilter(string $guid): string
    {
        $p = explode('-', strtolower($guid));
        if (count($p) !== 5) {
            return self::escape($guid);
        }
        $hex = implode('', array_reverse(str_split($p[0], 2))).implode('', array_reverse(str_split($p[1], 2)))
            .implode('', array_reverse(str_split($p[2], 2))).$p[3].$p[4];

        return '\\'.implode('\\', str_split($hex, 2));
    }

    /** Экранирование значения для LDAP-фильтра (RFC 4515) — без зависимости от расширения ldap. */
    public static function escape(string $value): string
    {
        return strtr($value, ['\\' => '\5c', '*' => '\2a', '(' => '\28', ')' => '\29', "\0" => '\00']);
    }
}
