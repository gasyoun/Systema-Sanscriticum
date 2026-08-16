<?php

declare(strict_types=1);

namespace App\Services\Membership;

final readonly class RecordingAccessDecision
{
    public function __construct(
        public bool $allowed,
        public bool $wouldAllow,
        public bool $shadow,
        public string $mode,
        public string $reason,
    ) {}

    public function notice(): ?string
    {
        if ($this->shadow && ! $this->wouldAllow) {
            return 'С 1 октября запись потребует уровня «Клуб». В сентябре видео остаётся открытым — это только предупреждение.';
        }

        if (! $this->allowed) {
            return 'Запись доступна с уровнем «Клуб». Расписание, ссылка на живое занятие, тексты, домашние задания и общение по купленному курсу остаются доступны.';
        }

        return null;
    }
}
