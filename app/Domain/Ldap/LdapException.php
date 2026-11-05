<?php

namespace App\Domain\Ldap;

use RuntimeException;

/** Ошибка связи с AD или настройки подключения (не «неверный пароль»). */
class LdapException extends RuntimeException {}
