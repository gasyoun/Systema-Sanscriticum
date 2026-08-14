<?php

declare(strict_types=1);

namespace App\Services;

/**
 * H2731 — one extract backend for a local PDF (never Telegram, never a URL).
 */
interface HindiPdfTextBackend
{
    public function name(): string;

    public function available(): bool;

    /**
     * Extract at most $maxPages from an ASCII-safe absolute path.
     * Empty string means "this backend produced no usable text".
     */
    public function extract(string $absolutePdf, int $maxPages): string;
}
