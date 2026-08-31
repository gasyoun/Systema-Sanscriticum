<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

/**
 * Okapi BM25 retriever over faq.md chunks (H2448 Track B v1).
 * Pure PHP — no vector deps; house prior art is Uprava H406 recall_index.py.
 */
final class Bm25FaqRetriever
{
    private const K1 = 1.5;

    private const B = 0.75;

    public function __construct(
        private readonly FaqCorpusParser $parser,
        private readonly RuTextNormalizer $ru = new RuTextNormalizer,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('features.faq_rag_suggester', false);
    }

    /**
     * @return list<array{chunk_id: string, title: string, heading_path: list<string>, snippet: string, source: string, score: float}>
     */
    public function retrieve(string $query, ?int $topK = null, ?string $path = null): array
    {
        $topK ??= (int) config('support.faq_rag.top_k', 3);
        $topK = max(1, $topK);

        $queryTokens = $this->ru->expandQuery($this->tokenize($query));
        if ($queryTokens === []) {
            return [];
        }

        $chunks = $this->parser->chunks($path);
        if ($chunks === []) {
            return [];
        }

        $docs = [];
        $df = [];
        $totalLen = 0;

        // H3766 B3 (правка 3): заголовок раздела весит больше тела. Заголовки
        // faq.md («Личный кабинет на сайте», «Техподдержка») почти дословно
        // повторяют вопрос студента, а в теле те же слова тонут среди сотен
        // других. Повтор токенов заголовка — самый дешёвый способ дать полю
        // вес, не переписывая BM25 на многополевой BM25F.
        $headingWeight = max(1, (int) config('support.faq_rag.heading_weight', 5));

        foreach ($chunks as $i => $chunk) {
            $tokens = $this->tokenize($chunk->body);
            $headingTokens = $this->tokenize(implode(' ', $chunk->headingPath));
            for ($w = 0; $w < $headingWeight; $w++) {
                foreach ($headingTokens as $ht) {
                    $tokens[] = $ht;
                }
            }
            $tf = array_count_values($tokens);
            $len = max(1, count($tokens));
            $docs[$i] = ['chunk' => $chunk, 'tf' => $tf, 'len' => $len];
            $totalLen += $len;
            foreach (array_keys($tf) as $term) {
                $df[$term] = ($df[$term] ?? 0) + 1;
            }
        }

        $n = count($docs);
        $avgdl = $totalLen / max(1, $n);
        $scores = [];

        foreach ($docs as $i => $doc) {
            $score = 0.0;
            foreach ($queryTokens as $term) {
                $freq = $doc['tf'][$term] ?? 0;
                if ($freq === 0) {
                    continue;
                }
                $nQi = $df[$term] ?? 0;
                $idf = log((($n - $nQi + 0.5) / ($nQi + 0.5)) + 1.0);
                $denom = $freq + self::K1 * (1.0 - self::B + self::B * ($doc['len'] / $avgdl));
                $score += $idf * (($freq * (self::K1 + 1.0)) / $denom);
            }
            if ($score > 0.0) {
                $scores[$i] = $score;
            }
        }

        arsort($scores, SORT_NUMERIC);

        $out = [];
        $count = 0;
        foreach ($scores as $i => $score) {
            /** @var FaqChunk $chunk */
            $chunk = $docs[$i]['chunk'];
            $citation = $chunk->toCitation();
            $citation['score'] = round((float) $score, 4);
            $out[] = $citation;
            $count++;
            if ($count >= $topK) {
                break;
            }
        }

        return $out;
    }

    /**
     * Money/policy categories (D payment, refund/pause policy via same gate):
     * refuse when best score is below the configured floor.
     *
     * @param  list<array{score?: float}>  $hits
     */
    public function passesThreshold(array $hits): bool
    {
        if ($hits === []) {
            return false;
        }

        $min = (float) config('support.faq_rag.min_score', 1.5);
        $best = (float) ($hits[0]['score'] ?? 0.0);

        return $best >= $min;
    }

    /**
     * Categories that must not invent policy without a FAQ hit.
     *
     * @return list<string>
     */
    public function moneyPolicyCategories(): array
    {
        $configured = config('support.faq_rag.money_policy_categories');
        if (is_array($configured) && $configured !== []) {
            return array_values(array_map('strval', $configured));
        }

        // D = payment/price; refunds/pause live under same policy risk surface.
        return ['D'];
    }

    /**
     * @return list<string>
     */
    public function tokenize(string $text): array
    {
        // H3766 B3 (правка 1): ё/е fold. Студенты пишут «е» там, где в faq.md
        // стоит «ё» («зачет»/«зачёт», «еще»/«ещё», «объем»/«объём»), и BM25
        // считал это разными термами. Свёртка идёт и по запросу, и по корпусу —
        // tokenize() один на оба.
        $text = $this->ru->fold($text);
        // Letters (incl. Cyrillic) and digits; drop punctuation.
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $m);
        $tokens = $m[0] ?? [];

        // Drop ultra-short stop-like tokens (RU prepositions/particles) lightly.
        $stop = [
            'и', 'в', 'во', 'на', 'не', 'что', 'это', 'как', 'а', 'но', 'или',
            'ли', 'же', 'бы', 'к', 'ко', 'о', 'об', 'от', 'по', 'за', 'из',
            'у', 'с', 'со', 'то', 'да', 'нет', 'the', 'a', 'an', 'of', 'to',
        ];

        $out = [];
        foreach ($tokens as $t) {
            if (mb_strlen($t) < 2) {
                continue;
            }
            if (in_array($t, $stop, true)) {
                continue;
            }
            // H3766 B3 (правка 2): усечение окончаний — одинаково для корпуса и
            // запроса, иначе «оплатить» перестанет находить «Оплата».
            $out[] = $this->ru->stem($t);
        }

        return $out;
    }
}
