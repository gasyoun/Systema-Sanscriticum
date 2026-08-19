<?php

declare(strict_types=1);

namespace App\Services\Discipline;

use Illuminate\Support\Carbon;

/**
 * Доказательная база «мы писали — он молчал» по одной паре (студент, курс)
 * внутри одного эпизода долга (H2746).
 *
 * Ключевая величина — `trailingUnanswered`: сколько ПОСЛЕДНИХ подряд контактов
 * остались без ответа. Именно она, а не общее число писем за всю историю,
 * соответствует правилу MG: «два обращения без ответа». Студент, который
 * ответил после второго письма и снова замолчал, начинает счёт заново.
 */
final class ContactEvidence
{
    /**
     * @param  list<array{source: string, at: string, channel: ?string, ref_id: ?int, answered: bool}>  $attempts
     * @param  list<array{source: string, at: string, ref_id: ?int}>  $responses
     */
    public function __construct(
        public readonly array $attempts,
        public readonly array $responses,
        public readonly int $trailingUnanswered,
        public readonly ?Carbon $lastContactAt,
        public readonly ?Carbon $silentSince,
    ) {}

    public static function empty(): self
    {
        return new self([], [], 0, null, null);
    }

    public function attemptCount(): int
    {
        return count($this->attempts);
    }

    public function hasResponse(): bool
    {
        return $this->responses !== [];
    }

    /** Момент последнего ответа студента — «он не молчал вот тогда». */
    public function lastResponseAt(): ?Carbon
    {
        $last = end($this->responses);

        return is_array($last) ? Carbon::parse($last['at']) : null;
    }
}
