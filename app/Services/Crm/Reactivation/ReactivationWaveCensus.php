<?php

declare(strict_types=1);

namespace App\Services\Crm\Reactivation;

use App\Services\Crm\Lifecycle\LifecycleCensus;

/**
 * H5288 — результат сухого прогона волны реактивации A: кто в списке, по
 * какому каналу и кого отсеяли с какой причиной. Та же форма, что у
 * {@see LifecycleCensus} — сухой прогон и любая
 * будущая отправка печатают одинаковый счёт.
 *
 * Отправкой не пахнет: объект хранит только числа и id.
 */
final class ReactivationWaveCensus
{
    /**
     * @param  list<array{user_id: int, segment: string, channel: string, template: string, days_since_payment: int|null}>  $sendList
     * @param  array<int, string>  $excluded  user_id => причина
     */
    public function __construct(
        public readonly array $sendList,
        public readonly array $excluded,
        public readonly int $candidateCount,
        public readonly string $generatedAt,
    ) {}

    /** @return array<string, int> канал => сколько адресатов */
    public function channelCounts(): array
    {
        return self::tally(array_column($this->sendList, 'channel'));
    }

    /** @return array<string, int> сегмент => сколько адресатов */
    public function segmentCounts(): array
    {
        return self::tally(array_column($this->sendList, 'segment'));
    }

    /** @return array<string, int> шаблон => сколько адресатов */
    public function templateCounts(): array
    {
        return self::tally(array_column($this->sendList, 'template'));
    }

    /** @return array<string, int> причина исключения => сколько карточек */
    public function exclusionCounts(): array
    {
        return self::tally(array_values($this->excluded));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt,
            'dry_run' => true,
            'messages_sent' => 0,
            'candidates' => $this->candidateCount,
            'send_list' => count($this->sendList),
            'by_segment' => $this->segmentCounts(),
            'by_channel' => $this->channelCounts(),
            'by_template' => $this->templateCounts(),
            'excluded' => count($this->excluded),
            'excluded_reasons' => $this->exclusionCounts(),
        ];
    }

    /**
     * @param  list<string>  $values
     * @return array<string, int>
     */
    private static function tally(array $values): array
    {
        $counts = array_count_values($values);
        arsort($counts);

        return $counts;
    }
}
