<?php

declare(strict_types=1);

namespace App\Services\Content;

use RuntimeException;

/**
 * Отказ публикации слота на одной площадке. permanent = повтор бесполезен
 * (гейт, нет настроек) — площадка сразу failed, без ожидания max_attempts.
 */
final class CalendarPublishException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }

    public static function permanent(string $message): self
    {
        return new self($message, true);
    }
}
