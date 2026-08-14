<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * H2731 — optional page-capped OCR when gs txtwrite is empty.
 *
 * Prod 14-08-2026 has gs but not tesseract; this backend stays dark until
 * the binary appears. Tests inject a fake backend instead of calling this.
 */
final class TesseractOcrBackend implements HindiPdfTextBackend
{
    public function name(): string
    {
        return 'tesseract-ocr';
    }

    public function available(): bool
    {
        return $this->tesseract() !== null && $this->gs() !== null;
    }

    public function extract(string $absolutePdf, int $maxPages): string
    {
        $tess = $this->tesseract();
        $gs = $this->gs();
        if ($tess === null || $gs === null) {
            return '';
        }

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h2731-ocr-'.bin2hex(random_bytes(4));
        if (! @mkdir($dir) && ! is_dir($dir)) {
            return '';
        }

        try {
            $pages = max(1, $maxPages);
            $pattern = $dir.DIRECTORY_SEPARATOR.'p-%d.png';
            $render = Process::timeout(90)->run([
                $gs,
                '-q',
                '-dNOPAUSE',
                '-dBATCH',
                '-dSAFER',
                '-dFirstPage=1',
                '-dLastPage='.(string) $pages,
                '-sDEVICE=png16m',
                '-r150',
                '-sOutputFile='.$pattern,
                $absolutePdf,
            ]);
            if (! $render->successful()) {
                return '';
            }

            $chunks = [];
            for ($i = 1; $i <= $pages; $i++) {
                $png = $dir.DIRECTORY_SEPARATOR.'p-'.$i.'.png';
                if (! is_file($png)) {
                    break;
                }
                $ocr = Process::timeout(60)->run([
                    $tess,
                    $png,
                    'stdout',
                    '-l',
                    'hin+rus+eng',
                    '--psm',
                    '6',
                ]);
                if ($ocr->successful()) {
                    $chunks[] = $ocr->output();
                }
            }

            return implode("\n", $chunks);
        } finally {
            $this->wipeDir($dir);
        }
    }

    private function tesseract(): ?string
    {
        $result = Process::timeout(8)->run(['tesseract', '--version']);

        return $result->successful() ? 'tesseract' : null;
    }

    private function gs(): ?string
    {
        foreach (['gs', 'gswin64c', 'gswin32c'] as $cand) {
            $result = Process::timeout(8)->run([$cand, '-v']);
            if ($result->successful()) {
                return $cand;
            }
        }

        return null;
    }

    private function wipeDir(string $dir): void
    {
        $files = glob($dir.DIRECTORY_SEPARATOR.'*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
