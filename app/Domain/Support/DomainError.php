<?php

namespace App\Domain\Support;

use RuntimeException;

/**
 * Нарушение бизнес-правила. HTTP-код: 409 — конфликт состояния (ячейка занята,
 * недопустимый переход), 422 — ошибка данных, 404 — не найдено.
 */
class DomainError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public static function notFound(string $message): self
    {
        return new self($message, 404);
    }

    public static function invalid(string $message, ?string $field = null): self
    {
        return new self($message, 422, $field);
    }
}
