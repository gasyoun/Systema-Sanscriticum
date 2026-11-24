<?php

declare(strict_types=1);

namespace App\Services\LearnerErrorCorpus;

/**
 * H6252 — pure extraction core for the learner-error corpus.
 *
 * 152-FZ CONTRACT: this class NEVER receives or emits raw personal data.
 * Input records must already be masked by SupportStudentPiiMasker. Only
 * Sanskrit-script (Devanagari) or IAST-diacritic tokens are emitted, plus
 * salt-free one-way hashes. No bodies, no names, no file paths in output.
 *
 * _Created: 25-11-2026 · Last updated: 25-11-2026_
 * _Dr. Mārcis Gasūns_
 */
final class LearnerErrorCorpusExtractor
{
    public const HEADER = ['anon_id', 'source', 'lemma', 'form', 'error_type', 'context_hash'];

    private const DEVANAGARI = '/[\x{0900}-\x{097F}]+/u';

    /** Latin token carrying at least one IAST diacritic (macron, ś, ṇ, ṭ, ḍ, ṛ, ñ …). */
    private const IAST = '/(?<![\p{L}\p{N}])[a-zA-Z][a-zA-Z\x{0100}-\x{017F}]*[\x{0100}-\x{017F}][a-zA-Z\x{0100}-\x{017F}]*(?![\p{L}\p{N}])/u';

    private const TOKEN = '/[\x{0900}-\x{097F}]+|(?<![\p{L}\p{N}])[a-zA-Z][a-zA-Z\x{0100}-\x{017F}]*[\x{0100}-\x{017F}][a-zA-Z\x{0100}-\x{017F}]*(?![\p{L}\p{N}])/u';

    /** Ordered cue → error_type; first match in the context window wins. */
    private const CUES = [
        ['/сандхи|редукц|слиян|sandhi/ui', 'sandhi'],
        ['/долг(?:ая|ое|ий)?|\bā\b|\bī\b|\bū\b/ui', 'long-vowel'],
        ['/огласовк|матра|matra|акшар|букв|написан|орфограф|а redundant/ui', 'orthography'],
        ['/падеж|вибхакт|суфикс|окончан|case\b/ui', 'case-ending'],
        ['/глагол|спряж|время н|verb\b/ui', 'verb-form'],
        ['/транслит|латиниц|романiz/ui', 'transliteration'],
        ['/каллиграф|хвост|петельк|изгиб|наклон|шрифт/ui', 'calligraphy'],
        ['/ударен|акцент|интонац|просод/ui', 'prosody'],
        ['/значен|перевод|лексик/ui', 'vocabulary'],
    ];

    /**
     * @param  array<int, array{anon_id: string, source: string, body: string}>  $records  already masked
     * @return array<int, array<string, string>>  one row per occurrence
     */
    public function extract(array $records): array
    {
        $rows = [];
        foreach ($records as $record) {
            $body = (string) ($record['body'] ?? '');
            if ($body === '') {
                continue;
            }
            preg_match_all(self::TOKEN, $body, $m);
            foreach ($m[0] as $token) {
                $lemma = mb_strtolower(trim($token), 'UTF-8');
                if ($this->isPersonalShaped($lemma)) {
                    continue;
                }
                $isDev = preg_match(self::DEVANAGARI, $lemma) === 1;
                if (! $isDev && preg_match(self::IAST, $lemma) !== 1) {
                    continue;
                }
                $offset = mb_strpos($body, $token) ?: 0;
                $window = mb_substr($body, max(0, $offset - 180), 360, 'UTF-8');
                $rows[] = [
                    'anon_id' => (string) ($record['anon_id'] ?? 'anon'),
                    'source' => (string) ($record['source'] ?? 'unknown'),
                    'lemma' => $lemma,
                    'form' => $isDev ? 'devanagari' : 'iast',
                    'error_type' => $this->classify($window),
                    'context_hash' => hash('sha256', $window),
                ];
            }
        }

        // Frequency ranking of problematic lemmas/forms (stable, deterministic).
        $freq = [];
        foreach ($rows as $r) {
            $key = $r['lemma']."\x1f".$r['form']."\x1f".$r['error_type'];
            $freq[$key] = ($freq[$key] ?? 0) + 1;
        }
        usort($rows, static function (array $a, array $b) use ($freq): int {
            $ka = $a['lemma']."\x1f".$a['form']."\x1f".$a['error_type'];
            $kb = $b['lemma']."\x1f".$b['form']."\x1f".$b['error_type'];
            return ($freq[$kb] <=> $freq[$ka]) ?: strcmp($ka, $kb);
        });

        return $rows;
    }

    private function classify(string $window): string
    {
        foreach (self::CUES as [$pattern, $type]) {
            if (preg_match($pattern, $window) === 1) {
                return $type;
            }
        }

        return 'orthography'; // correction context default
    }

    /** Belt-and-braces: never let an e-mail, phone or mask tag become a lemma. */
    private function isPersonalShaped(string $token): bool
    {
        return str_contains($token, '@')
            || str_contains($token, 'PII')
            || preg_match('/\d{4,}/u', $token) === 1;
    }

    /** @return string CSV with BOM-less UTF-8, LF endings, header row. */
    public function toCsv(array $rows): string
    {
        $out = implode(',', self::HEADER)."\n";
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($fh, array_map(static fn (string $h): string => (string) ($r[$h] ?? ''), self::HEADER));
        }
        rewind($fh);
        $out .= stream_get_contents($fh);
        fclose($fh);

        return $out;
    }
}
