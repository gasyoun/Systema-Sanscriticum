<?php

declare(strict_types=1);

namespace App\Services\Content;

use InvalidArgumentException;

/**
 * Guardrail against a full-transcript leak through a "public quote" field
 * (H1547, ARCHITECTURE §3): a ContentCandidate's `quote` is meant to be a
 * short teaser, never the paid lecture body. Hard-fails on publish/auto;
 * callers doing a draft-only save may catch and soft-flag instead.
 */
final class QuotePolicy
{
    private const MAX_SENTENCES = 2;

    /**
     * @throws InvalidArgumentException if the quote exceeds MAX_SENTENCES sentences.
     */
    public static function assertPublicQuote(?string $quote): void
    {
        if ($quote === null || trim($quote) === '') {
            return;
        }

        $count = self::sentenceCount($quote);
        if ($count > self::MAX_SENTENCES) {
            throw new InvalidArgumentException(
                "QuotePolicy: quote has {$count} sentences, max ".self::MAX_SENTENCES.' allowed.'
            );
        }
    }

    public static function sentenceCount(string $text): int
    {
        return preg_match_all('/[.!?]+/u', trim($text));
    }
}
