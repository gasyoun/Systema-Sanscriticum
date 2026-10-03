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
     * @param  array<string, array<string, int>>  $excludedBySegment  сегмент => причина => сколько (H5821)
     */
    public function __construct(
        public readonly array $sendList,
        public readonly array $excluded,
        public readonly int $candidateCount,
        public readonly string $generatedAt,
        public readonly array $excludedBySegment = [],
    ) {}

    /** @return array<string, int> канал => сколько адресатов */
    public function channelCounts(): array
    {
        return self::tally(array_column($this->sendList, 'channel'));
    }

    /**
     * H5821 (C1): канал адресатов только пула уснувших (563, `lapsed`) —
     * сегмент, по которому MG принимает решение волны.
     *
     * @return array<string, int> канал => сколько адресатов
     */
    public function lapsedChannelCounts(): array
    {
        return self::tally(array_column(
            array_values(array_filter($this->sendList, fn (array $row): bool => $row['segment'] === ReactivationWaveCohort::SEGMENT_LAPSED)),
            'channel',
        ));
    }

    /**
     * H5821 (C1): глубина сна пула уснувших (563) по границам из
     * `config('reactivation_wave.dormancy_bucket_edges')` (в днях). Метки
     * человекочитаемые: до первой границы, между границами, дальше — «более N лет».
     *
     * @param  list<int>  $edges  возрастающие границы в днях, напр. [730, 1095]
     * @return array<string, int> корзина => сколько уснувших
     */
    public function lapsedDormancyBuckets(array $edges): array
    {
        $edges = array_values(array_filter($edges, fn ($edge): bool => is_numeric($edge) && (int) $edge > 0));
        sort($edges);

        $labels = [];
        $prev = null;
        foreach ($edges as $edge) {
            $labels[] = $prev === null
                ? sprintf('до %d дней', $edge)
                : sprintf('%d–%d дней', $prev, $edge - 1);
            $prev = (int) $edge;
        }
        $labels[] = $prev === null ? 'все' : sprintf('более %d дней', $prev);

        $counts = array_fill_keys($labels, 0);
        foreach ($this->sendList as $row) {
            if ($row['segment'] !== ReactivationWaveCohort::SEGMENT_LAPSED) {
                continue;
            }
            $days = max(0, (int) ($row['days_since_payment'] ?? 0));
            $index = count($edges);
            foreach ($edges as $i => $edge) {
                if ($days < (int) $edge) {
                    $index = (int) $i;

                    break;
                }
            }
            $counts[$labels[$index]] = ($counts[$labels[$index]] ?? 0) + 1;
        }

        return $counts;
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

    /**
     * H5821 (C1): исключения по сегментам — у пула уснувших (563) своя
     * лестница причин, у непродолживших (919) своя.
     *
     * @return array<string, array<string, int>> сегмент => причина => сколько
     */
    public function excludedCountsBySegment(): array
    {
        $out = [];
        foreach ($this->excludedBySegment as $segment => $reasons) {
            $counts = $reasons;
            arsort($counts);
            $out[$segment] = $counts;
        }
        ksort($out);

        return $out;
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
            'excluded_by_segment' => $this->excludedCountsBySegment(),
            'lapsed_by_channel' => $this->lapsedChannelCounts(),
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
