<?php

declare(strict_types=1);

namespace App\Services\Discipline;

use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Участник учебного чата по ростеру + вердикт правила «молчит и урок закрыт».
 * user = null — аккаунт не сопоставлен ни с одной учёткой.
 */
final class ChatSilenceCandidate
{
    /**
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly string $telegramChatId,
        public readonly string $telegramUserId,
        public readonly ?string $memberName,
        public readonly ?string $memberUsername,
        public readonly Group $group,
        public readonly ?User $user,
        public readonly ?CarbonInterface $lastMessageAt,
        public readonly ?Lesson $lesson,
        public readonly array $blockers,
    ) {}

    public function isEligible(): bool
    {
        return $this->user !== null && $this->blockers === [];
    }

    public function redactedSubject(): string
    {
        return $this->user !== null ? 'user#'.$this->user->id : 'tg#'.$this->telegramUserId;
    }

    public function displayName(): string
    {
        $name = $this->user?->name ?: $this->memberName ?: $this->user?->email;
        $handle = $this->memberUsername ? ' @'.$this->memberUsername : '';

        return trim(($name ?: 'tg '.$this->telegramUserId).$handle);
    }
}
