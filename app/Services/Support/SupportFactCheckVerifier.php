<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * H4589: детерминированная сверка черновика суфлёра С ЕГО СОБСТВЕННЫМИ
 * фактами (никакого LLM, никакой сети — то же обещание, что у
 * {@see SupportAnswerFactResolver}). Для категорий D/E/F (H3999) черновик
 * формулирует LLM по фактам LMS — эта проверка ловит расхождение между тем,
 * что LLM НАПИСАЛА в тексте, и тем, что резолвер ДЕЙСТВИТЕЛЬНО посчитал:
 * цена/ссылка/дата, упомянутая в draft_text, но отсутствующая или другая
 * в facts, — это либо галлюцинация LLM, либо устаревший факт.
 *
 * СОЗНАТЕЛЬНО не трогает отправку. H4440 (MG 09-09-2026, явный рулинг) снял
 * код-уровневый draft_only-отказ с кнопки «Отправить как есть» — «кнопка
 * теперь под КАЖДЫМ черновиком... отправитель РЕШАЕТ нажатием». Автоматически
 * гасить кнопку по результату этой проверки означало бы тихо отменить тот
 * рулинг без нового явного решения MG — этот класс делает только измерение
 * (пишется в facts['fact_check'], читается в /admin и в подсказке как
 * предупреждение) и намеренно не имеет метода suppress()/deny().
 */
final class SupportFactCheckVerifier
{
    public const STATUS_MATCH = 'match';

    public const STATUS_MISMATCH = 'mismatch';

    public const STATUS_UNVERIFIABLE = 'unverifiable';

    /** Допуск сравнения сумм, рублей — тот же порядок, что у резолвера. */
    private const MONEY_TOLERANCE = 1.0;

    /** Ниже этой суммы число в тексте не считаем денежным утверждением. */
    private const MONEY_FLOOR = 100.0;

    /**
     * @param  array<string, mixed>  $facts  facts-массив, ранее собранный
     *                                       {@see SupportAnswerFactResolver} —
     *                                       собственные источники ЭТОГО черновика,
     *                                       а не произвольная база знаний.
     * @return array{status: string, mismatches: list<array{type: string, claimed: mixed, known: list<mixed>}>, checked: list<string>}
     */
    public function verify(string $draftText, array $facts): array
    {
        $mismatches = [];
        $checked = [];

        $knownAmounts = $this->collectAmounts($facts);
        $knownUrls = $this->collectUrls($facts);

        foreach ($this->extractAmounts($draftText) as $claimed) {
            if ($knownAmounts === []) {
                continue; // нечего сверять — резолвер не давал денежных фактов
            }
            $checked[] = 'price';
            if (! $this->amountMatchesAny($claimed, $knownAmounts)) {
                $mismatches[] = ['type' => 'price', 'claimed' => $claimed, 'known' => $knownAmounts];
            }
        }

        foreach ($this->extractUrls($draftText) as $claimed) {
            if ($knownUrls === []) {
                continue;
            }
            $checked[] = 'link';
            if (! in_array($claimed, $knownUrls, true)) {
                $mismatches[] = ['type' => 'link', 'claimed' => $claimed, 'known' => $knownUrls];
            }
        }

        $status = match (true) {
            $mismatches !== [] => self::STATUS_MISMATCH,
            $checked === [] => self::STATUS_UNVERIFIABLE,
            default => self::STATUS_MATCH,
        };

        return ['status' => $status, 'mismatches' => $mismatches, 'checked' => array_values(array_unique($checked))];
    }

    /** @return list<float> */
    private function extractAmounts(string $text): array
    {
        if (! preg_match_all('/(\d[\d\s]{1,9})\s?(?:₽|руб)/iu', $text, $matches)) {
            return [];
        }

        $amounts = [];
        foreach ($matches[1] as $raw) {
            $value = (float) str_replace([' ', "\u{00A0}"], '', $raw);
            if ($value >= self::MONEY_FLOOR) {
                $amounts[] = $value;
            }
        }

        return $amounts;
    }

    /** @return list<string> */
    private function extractUrls(string $text): array
    {
        if (! preg_match_all('/https?:\/\/\S+/iu', $text, $matches)) {
            return [];
        }

        return array_map(static fn (string $url): string => rtrim($url, '.,;)'), $matches[0]);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<float>
     */
    private function collectAmounts(array $facts): array
    {
        $amounts = [];
        array_walk_recursive($facts, function ($value, $key) use (&$amounts): void {
            if (is_numeric($value) && preg_match('/price|amount|balance|due|paid|cost|сумм|цен/iu', (string) $key)) {
                $amounts[] = (float) $value;
            }
        });

        return $amounts;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    private function collectUrls(array $facts): array
    {
        $urls = [];
        array_walk_recursive($facts, function ($value) use (&$urls): void {
            if (is_string($value) && preg_match('/^https?:\/\//i', $value)) {
                $urls[] = rtrim($value, '.,;)');
            }
        });

        return array_values(array_unique($urls));
    }

    /** @param  list<float>  $known */
    private function amountMatchesAny(float $claimed, array $known): bool
    {
        foreach ($known as $value) {
            if (abs($value - $claimed) <= self::MONEY_TOLERANCE) {
                return true;
            }
        }

        return false;
    }
}
