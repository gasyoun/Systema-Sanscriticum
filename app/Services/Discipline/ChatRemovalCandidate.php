<?php

declare(strict_types=1);

namespace App\Services\Discipline;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Одна проверенная тройка (студент, курс, учебный чат) из отчёта H2746.
 *
 * Кандидат существует даже когда он НЕ проходит: `blockers` перечисляют, чего
 * не хватило. Отчёт, который печатает только годных, бесполезен оператору —
 * «почему Х не в списке» тогда не на чем разобрать.
 */
final class ChatRemovalCandidate
{
    /**
     * @param  list<int>  $debtBlockNumbers
     * @param  list<string>  $blockers  пустой список = кандидат проходит правило
     */
    public function __construct(
        public readonly User $user,
        public readonly int $courseId,
        public readonly string $courseTitle,
        public readonly ?Group $group,
        public readonly string $telegramChatId,
        public readonly int $daysOverdue,
        public readonly ?float $debtAmount,
        public readonly array $debtBlockNumbers,
        public readonly ?int $referenceBlock,
        public readonly ContactEvidence $evidence,
        public readonly array $blockers,
        public readonly int $reinstatementFee,
        public readonly ?Carbon $episodeSince = null,
    ) {}

    public function isEligible(): bool
    {
        return $this->blockers === [];
    }

    public function chatLabel(): string
    {
        return $this->group?->name ?? $this->telegramChatId;
    }

    /**
     * Обезличенная подпись студента для отчётов и логов: имя не печатаем, id
     * достаточно, чтобы оператор нашёл карточку. PII в stdout/файлы отчётов —
     * прямой fail-критерий handoff'а H2746.
     */
    public function redactedSubject(): string
    {
        return 'user#'.$this->user->id;
    }
}
