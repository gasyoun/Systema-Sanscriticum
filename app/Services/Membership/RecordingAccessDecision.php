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
            return $this->reason === 'club_stream_requires_club'
                ? 'С 1 октября запись клубного эфира потребует уровня «Клуб». В сентябре видео остаётся открытым — это только предупреждение.'
                : 'С 1 октября запись потребует уровня «Клуб». В сентябре видео остаётся открытым — это только предупреждение.';
        }

        if (! $this->allowed) {
            if ($this->reason === 'club_stream_requires_club') {
                return 'Запись клубного эфира доступна с уровнем «Клуб».';
            }

            if ($this->reason === 'course_not_purchased' || $this->reason === 'course_purchase') {
                return 'Запись урока открывается покупкой этого курса. Членство в клубе её не подменяет.';
            }

            return 'Запись доступна с уровнем «Клуб». Расписание, ссылка на живое занятие, тексты, домашние задания и общение по купленному курсу остаются доступны.';
        }

        return null;
    }
}
