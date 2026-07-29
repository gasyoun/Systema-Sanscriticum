<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

/**
 * Одна пропажа или расхождение. Важность решает, чем это становится дальше:
 *
 *  • critical — предохранителя нет. Авария из docs/server-resource-guards.md
 *               снова физически возможна. guards:verify падает, cabinet:probe
 *               шлёт critical-тревогу.
 *  • warning  — предохранитель есть, но разошёлся с репозиторием, или пропала
 *               наблюдаемость: аварии это не вызовет, но разбирать её будет
 *               нечем. guards:verify тоже падает (тихая деградация — как раз то,
 *               из-за чего этот handoff существует), тревога — soft.
 *  • info     — не наша власть: своп заводится на хосте Proxmox, внутри LXC
 *               недостижим. Печатаем, но код возврата не портим.
 */
final class GuardFinding
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    public const INFO = 'info';

    public function __construct(
        public readonly string $severity,
        public readonly string $guard,
        public readonly string $message,
    ) {}

    public static function critical(string $guard, string $message): self
    {
        return new self(self::CRITICAL, $guard, $message);
    }

    public static function warning(string $guard, string $message): self
    {
        return new self(self::WARNING, $guard, $message);
    }

    public static function info(string $guard, string $message): self
    {
        return new self(self::INFO, $guard, $message);
    }

    public function line(): string
    {
        return '['.$this->severity.'] '.$this->guard.': '.$this->message;
    }

    /**
     * @return array{severity: string, guard: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'guard' => $this->guard,
            'message' => $this->message,
        ];
    }
}
