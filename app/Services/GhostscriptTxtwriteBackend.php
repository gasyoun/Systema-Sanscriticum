<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * H2731 — Ghostscript txtwrite (already on prod as /usr/bin/gs 10.05.1).
 *
 * Callers must copy the PDF to an ASCII temp path first: gs -dSAFER refuses
 * Unicode filenames (measured on lesson 1723, 14-08-2026).
 */
final class GhostscriptTxtwriteBackend implements HindiPdfTextBackend
{
    public function name(): string
    {
        return 'gs-txtwrite';
    }

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    public function extract(string $absolutePdf, int $maxPages): string
    {
        $bin = $this->binary();
        if ($bin === null) {
            return '';
        }

        $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h2731-'.bin2hex(random_bytes(6)).'.txt';
        try {
            $result = Process::timeout(60)->run([
                $bin,
                '-q',
                '-dNOPAUSE',
                '-dBATCH',
                '-dSAFER',
                '-dFirstPage=1',
                '-dLastPage='.(string) max(1, $maxPages),
                '-sDEVICE=txtwrite',
                '-sOutputFile='.$out,
                $absolutePdf,
            ]);
            if (! $result->successful() || ! is_file($out)) {
                return '';
            }

            return (string) file_get_contents($out);
        } finally {
            if (is_file($out)) {
                @unlink($out);
            }
        }
    }

    private function binary(): ?string
    {
        foreach (['gs', 'gswin64c', 'gswin32c'] as $cand) {
            $result = Process::timeout(8)->run([$cand, '-v']);
            if ($result->successful()) {
                return $cand;
            }
        }

        return null;
    }
}
