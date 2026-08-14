<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * H2731 — bounded PDF → sibling .txt for HindiAttachmentTextExtractor.
 *
 * Dry-run by default. Never fetches Telegram. Never invents text.
 * Caps: first N pages, skip if the file is over the byte ceiling.
 */
final class HindiPdfSidecarWriter
{
    public const DEFAULT_MAX_PAGES = 8;

    public const DEFAULT_MAX_BYTES = 15_000_000;

    public const STATUS_WRITTEN = 'written';

    public const STATUS_WOULD_WRITE = 'would_write';

    public const STATUS_SKIP = 'skip';

    public const STATUS_EXISTS = 'sidecar_exists';

    public const REASON_NOT_PDF = 'not_pdf';

    public const REASON_MISSING = 'missing';

    public const REASON_OVER_BYTE_CAP = 'pdf_over_byte_cap';

    public const REASON_NO_BACKEND = 'pdf_no_backend';

    public const REASON_NO_TEXT_LAYER = 'pdf_no_text_layer';

    public const REASON_EMPTY = 'empty';

    public const REASON_EXISTS = 'sidecar_exists';

    /**
     * @param  list<HindiPdfTextBackend>|null  $backends
     */
    public function __construct(private readonly ?array $backends = null) {}

    public function sidecarPath(string $pdfPath): string
    {
        $sidecar = preg_replace('/\.pdf$/i', '.txt', $pdfPath);

        return is_string($sidecar) ? $sidecar : $pdfPath.'.txt';
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(string $path, int $maxPages = self::DEFAULT_MAX_PAGES, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        return $this->run($path, $maxPages, $maxBytes, apply: false, force: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(
        string $path,
        int $maxPages = self::DEFAULT_MAX_PAGES,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        bool $force = false,
    ): array {
        return $this->run($path, $maxPages, $maxBytes, apply: true, force: $force);
    }

    /**
     * @return array<string, mixed>
     */
    private function run(string $path, int $maxPages, int $maxBytes, bool $apply, bool $force): array
    {
        $maxPages = max(1, $maxPages);
        $maxBytes = max(1, $maxBytes);
        $sidecar = $this->sidecarPath($path);
        $base = [
            'path' => $path,
            'sidecar' => $sidecar,
            'status' => self::STATUS_SKIP,
            'reason' => null,
            'backend' => null,
            'bytes' => 0,
            'max_pages' => $maxPages,
            'max_bytes' => $maxBytes,
            'chars' => 0,
            'has_devanagari' => false,
            'has_cyrillic' => false,
            'truncated' => true,
        ];

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            $base['reason'] = self::REASON_NOT_PDF;

            return $base;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            $base['reason'] = self::REASON_MISSING;

            return $base;
        }

        $bytes = (int) $disk->size($path);
        $base['bytes'] = $bytes;

        if (! $force && $disk->exists($sidecar)) {
            $base['status'] = self::STATUS_EXISTS;
            $base['reason'] = self::REASON_EXISTS;

            return $base;
        }

        if ($bytes > $maxBytes) {
            $base['reason'] = self::REASON_OVER_BYTE_CAP;

            return $base;
        }

        $backends = $this->backends();
        if ($backends === []) {
            $base['reason'] = self::REASON_NO_BACKEND;

            return $base;
        }

        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'h2731-'.bin2hex(random_bytes(8)).'.pdf';
        try {
            $raw = $disk->get($path);
            if (! is_string($raw) || $raw === '') {
                $base['reason'] = self::REASON_MISSING;

                return $base;
            }
            file_put_contents($tmp, $raw);

            $text = '';
            $used = null;
            foreach ($backends as $backend) {
                if (! $backend->available()) {
                    continue;
                }
                $text = $backend->extract($tmp, $maxPages);
                $used = $backend->name();
                if (trim(str_replace("\0", '', $text)) !== '') {
                    break;
                }
            }

            if ($used === null) {
                $base['reason'] = self::REASON_NO_BACKEND;

                return $base;
            }

            $text = trim(str_replace("\0", '', $text));
            $base['backend'] = $used;
            $base['chars'] = strlen($text);
            $base['has_devanagari'] = (bool) preg_match('/\p{Devanagari}/u', $text);
            $base['has_cyrillic'] = (bool) preg_match('/\p{Cyrillic}/u', $text);

            if ($text === '') {
                $base['reason'] = self::REASON_NO_TEXT_LAYER;

                return $base;
            }

            if ($apply) {
                $disk->put($sidecar, $text);
                $base['status'] = self::STATUS_WRITTEN;
            } else {
                $base['status'] = self::STATUS_WOULD_WRITE;
            }

            return $base;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @return list<HindiPdfTextBackend>
     */
    private function backends(): array
    {
        if ($this->backends !== null) {
            return $this->backends;
        }

        $out = [];
        $gs = new GhostscriptTxtwriteBackend;
        if ($gs->available()) {
            $out[] = $gs;
        }
        $ocr = new TesseractOcrBackend;
        if ($ocr->available()) {
            $out[] = $ocr;
        }

        return $out;
    }
}
