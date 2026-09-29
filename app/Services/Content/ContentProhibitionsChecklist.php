<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * Pre-send checklist for the marketing autopilots (H5020, MG 16-09-2026).
 *
 * Encodes the ratified §2.8 prohibitions (config/content_prohibitions.php)
 * as deterministic pattern checks run by BOTH publishers before the first
 * HTTP call: content:publish-due (VK via n8n) and stories:publish-due
 * (@rusamskrtam). A `block` hit means the post must NOT go out — the
 * publisher holds it back for a human edit and journals the reason. A
 * `warn` hit goes out but is listed in the Monday digest
 * (content:autopilot-digest) so MG can verify the price / date it names.
 *
 * Pure function over text — no DB, no HTTP, no side effects.
 */
final class ContentProhibitionsChecklist
{
    public const SEVERITY_BLOCK = 'block';

    public const SEVERITY_WARN = 'warn';

    /**
     * @return array{ok: bool, blocks: list<array{rule: string, label: string, match: string}>, warns: list<array{rule: string, label: string, match: string}>}
     */
    public function check(string $text): array
    {
        $blocks = [];
        $warns = [];

        /** @var array<string, array{severity: string, label: string, patterns: list<string>}> $rules */
        $rules = (array) config('content_prohibitions.rules', []);

        foreach ($rules as $rule => $spec) {
            $severity = (string) ($spec['severity'] ?? self::SEVERITY_BLOCK);
            $label = (string) ($spec['label'] ?? $rule);

            foreach ((array) ($spec['patterns'] ?? []) as $pattern) {
                if (preg_match($pattern, $text, $m) !== 1) {
                    continue;
                }

                $hit = ['rule' => $rule, 'label' => $label, 'match' => mb_substr((string) $m[0], 0, 80)];

                if ($severity === self::SEVERITY_WARN) {
                    $warns[] = $hit;
                } else {
                    $blocks[] = $hit;
                }

                break; // one hit per rule is enough to decide
            }
        }

        foreach ($this->foreignHandles($text) as $handle) {
            $blocks[] = [
                'rule' => 'crm_personal_data',
                'label' => '§2.8 · чужой Telegram-хэндл (не из allowed_handles)',
                'match' => '@'.$handle,
            ];

            break;
        }

        return ['ok' => $blocks === [], 'blocks' => $blocks, 'warns' => $warns];
    }

    /** One-line journal text for a held-back post. */
    public function journalLine(array $result): string
    {
        $parts = array_map(
            static fn (array $hit): string => $hit['rule'].' («'.$hit['match'].'»)',
            $result['blocks'],
        );

        return 'prohibition-hold §2.8: '.implode('; ', $parts);
    }

    /** @return list<string> */
    private function foreignHandles(string $text): array
    {
        if (preg_match_all('/(?<![\w.])@([A-Za-z][A-Za-z0-9_]{3,31})\b/u', $text, $m) < 1) {
            return [];
        }

        $allowed = array_map('mb_strtolower', (array) config('content_prohibitions.allowed_handles', []));

        return array_values(array_filter(
            array_unique($m[1]),
            static fn (string $h): bool => ! in_array(mb_strtolower($h), $allowed, true),
        ));
    }
}
