<?php

declare(strict_types=1);

namespace App\Services\Content;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;

/**
 * Score vk-ors top_posts_by_likes rows for evergreen recycling (H1565, content
 * calendar W2). Topic keyword patterns are the {книга, словарь, pdf, текст}
 * subset of D14, ported from IndologyScholars/vk-ors/vk_ors_archive/insights.py
 * TOPICS — do not re-derive the tagging pass, the source CSV has no per-post
 * topic column so it has to be re-applied to the excerpt text here.
 *
 * @phpstan-type CsvRow array<string, string>
 * @phpstan-type ScoredPost array{id: string, url: string, date: string, excerpt: string, likes: int, topic: string}
 */
final class EvergreenScorer
{
    private const MIN_AGE_MONTHS = 12;

    /** @var array<string, string> topic => PCRE pattern, ported from insights.py TOPICS (D14 subset) */
    private const TOPIC_PATTERNS = [
        'словарь' => '/словар|dictionary|lexicon|кош[аеу]?|koś|monier|monier-williams|бётлинг|böhtlingk|бётлингк|апте|\bapte\b|\bpwg?\b|\bmw\b|тезаурус/iu',
        'книга' => '/книг|моногра|издани|djvu|скан|учебн?ое пособ|библиотек|книгохран|печатн/iu',
        'pdf' => '/\bpdf\b|\.pdf|ocr|распозна|отскан/iu',
        'текст' => '/махабхарат|рамаян|\bгита\b|бхагавад|шлок|стих|перевод|упаниш|веды|ведийск|пуран|коммент/iu',
    ];

    /** Promo exclusion (IMPLEMENTATION doc defaults log, tuned for W2). */
    private const PROMO_PATTERN = '/скидк|\bруб\b|₽|запис.{0,20}марафон|марафон.{0,20}цена/iu';

    /**
     * @param  list<CsvRow>  $topPosts  rows from top_posts_by_likes.csv
     * @param  list<string>  $excludeSourceRefs  post ids already used within the de-dupe window (D14)
     * @return list<ScoredPost> ranked likes DESC
     */
    public function score(array $topPosts, DateTimeInterface $now, array $excludeSourceRefs = []): array
    {
        $excluded = array_flip($excludeSourceRefs);
        $cutoff = DateTimeImmutable::createFromInterface($now)->modify('-'.self::MIN_AGE_MONTHS.' months');

        $scored = [];
        foreach ($topPosts as $row) {
            $id = trim($row['id'] ?? '');
            if ($id === '' || isset($excluded[$id])) {
                continue;
            }

            $excerpt = trim((string) ($row['excerpt'] ?? ''));
            if ($excerpt === '' || preg_match(self::PROMO_PATTERN, $excerpt) === 1) {
                continue;
            }

            $date = $this->parseDate((string) ($row['date'] ?? ''));
            if ($date === null || $date > $cutoff) {
                continue; // too recent (age < 12 months)
            }

            $topic = $this->matchTopic($excerpt);
            if ($topic === null) {
                continue;
            }

            $scored[] = [
                'id' => $id,
                'url' => (string) ($row['url'] ?? ''),
                'date' => $date->format('Y-m-d'),
                'excerpt' => $excerpt,
                'likes' => (int) ($row['likes'] ?? 0),
                'topic' => $topic,
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['likes'] <=> $a['likes']);

        return $scored;
    }

    private function matchTopic(string $text): ?string
    {
        foreach (self::TOPIC_PATTERNS as $topic => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $topic;
            }
        }

        return null;
    }

    private function parseDate(string $raw): ?DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return null;
        }
    }
}
