<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * Deterministic §3–§8 gate for CuratorAi-polished forward-draft copy
 * (docs/VOICE_CONTRACT_ORS_VK_WALL_RU.md, H1791 — the contract is measured
 * enough to lint mechanically instead of trusting the prompt alone).
 */
final class VoiceContractLinter
{
    /** §3 banned-phrase table, verbatim; matched case-insensitively. A contract
     *  change updates both in one PR (contract §10). */
    private const BANNED_PHRASES = [
        'уникальная возможность',
        'не упустите',
        'успейте',
        'спешите',
        'приглашаем вас',
        'дорогие друзья',
        'дорогие родители',
        'погрузитесь',
        'погрузимся',
        'откройте для себя',
    ];

    /** §8: target is 400–1 200 chars; this is the outer "clearly broken" ceiling. */
    private const MAX_LENGTH = 2000;

    /** @return string|null the violated rule name, or null if $text passes. */
    public static function violation(string $text): ?string
    {
        $lower = mb_strtolower($text);
        foreach (self::BANNED_PHRASES as $phrase) {
            if (mb_strpos($lower, $phrase) !== false) {
                return "banned_phrase:{$phrase}";
            }
        }

        if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}]/u', $text) === 1) {
            return 'emoji';
        }

        if (preg_match('/#[\p{L}\p{N}_]+/u', $text) === 1) {
            return 'hashtag';
        }

        $firstLine = trim(explode("\n", trim($text), 2)[0]);
        if (str_ends_with($firstLine, '?')) {
            return 'question_first_line';
        }

        if (preg_match('/\*\*.+?\*\*|`[^`]+`|^#{1,6}\s|\[.+?\]\(.+?\)/mu', $text) === 1) {
            return 'markdown';
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            return 'length';
        }

        return null;
    }
}
