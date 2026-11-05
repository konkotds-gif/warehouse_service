<?php

namespace App\Domain\Ldap;

use RuntimeException;

/**
 * Вход отклонён по причине самой учётной записи (неверный пароль, отключена, заблокирована…).
 * $countsAsAttempt — учитывать ли попытку в ограничении «5 неудачных попыток».
 */
class LdapAuthFailed extends RuntimeException
{
    public function __construct(string $message, public bool $countsAsAttempt = true)
    {
        parent::__construct($message);
    }
}
