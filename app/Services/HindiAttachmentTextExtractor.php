<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * H2444 — cheap text from files already stored on a Hindi lesson.
 *
 * Reads Lesson.attachments and teacher-posted homework_attachments.
 * txt/md: UTF-8 read. docx: ZipArchive + word/document.xml (no PhpWord).
 * PDF: only a sibling .txt extract, never OCR, never a Telegram fetch.
 */
final class HindiAttachmentTextExtractor
{
    public const REASON_MISSING = 'missing';

    public const REASON_EMPTY = 'empty';

    public const REASON_BINARY = 'binary';

    public const REASON_PDF_NO_EXTRACT = 'pdf_no_extract';

    public const REASON_DOCX_UNREADABLE = 'docx_unreadable';

    public const SOURCE_ATTACHMENTS = 'attachments';

    public const SOURCE_HOMEWORK = 'homework_attachments';

    /**
     * @return list<array{
     *     path: string,
     *     name: string,
     *     ext: string,
     *     source: string,
     *     extractable: bool,
     *     reason: string|null,
     *     text: string,
     *     url: string
     * }>
     */
    public function filesFor(Lesson $lesson): array
    {
        $out = [];
        foreach ($this->listedPaths($lesson) as $row) {
            $out[] = $this->inspect($row['path'], $row['source']);
        }

        return $out;
    }

    /**
     * @return list<array{formatted_time: string, start: float, end: float, text: string, safe_text: string}>
     */
    public function sentencesFrom(Lesson $lesson): array
    {
        $sentences = [];
        $index = 0;
        foreach ($this->filesFor($lesson) as $file) {
            if (! $file['extractable'] || $file['text'] === '') {
                continue;
            }
            foreach ($this->splitSentences($file['text']) as $line) {
                $sentences[] = [
                    'formatted_time' => '',
                    'start' => (float) $index,
                    'end' => (float) $index,
                    'text' => $line,
                    'safe_text' => e($line),
                ];
                $index++;
            }
        }

        return $sentences;
    }

    /**
     * @return list<array{path: string, source: string}>
     */
    public function listedPaths(Lesson $lesson): array
    {
        $rows = [];
        foreach ([
            self::SOURCE_ATTACHMENTS => $lesson->attachments,
            self::SOURCE_HOMEWORK => $lesson->homework_attachments,
        ] as $source => $raw) {
            foreach ((array) $raw as $item) {
                $path = $this->normalizePath($item);
                if ($path === '') {
                    continue;
                }
                $rows[] = ['path' => $path, 'source' => $source];
            }
        }

        return $rows;
    }

    /**
     * @param  mixed  $item
     */
    public function normalizePath($item): string
    {
        if (is_array($item)) {
            $item = $item['path'] ?? $item['name'] ?? '';
        }
        $path = trim(str_replace('\\', '/', (string) $item));
        $path = ltrim($path, '/');

        return $path;
    }

    /**
     * @return array{
     *     path: string,
     *     name: string,
     *     ext: string,
     *     source: string,
     *     extractable: bool,
     *     reason: string|null,
     *     text: string,
     *     url: string
     * }
     */
    private function inspect(string $path, string $source): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $name = basename($path);
        $base = [
            'path' => $path,
            'name' => $name,
            'ext' => $ext,
            'source' => $source,
            'extractable' => false,
            'reason' => null,
            'text' => '',
            'url' => asset('storage/'.$path),
        ];

        if (! Storage::disk('public')->exists($path)) {
            $base['reason'] = self::REASON_MISSING;

            return $base;
        }

        if (in_array($ext, ['txt', 'md', 'text'], true)) {
            return $this->withText($base, (string) Storage::disk('public')->get($path));
        }

        if ($ext === 'docx') {
            $absolute = Storage::disk('public')->path($path);
            $text = $this->extractDocx($absolute);
            if ($text === null) {
                $base['reason'] = self::REASON_DOCX_UNREADABLE;

                return $base;
            }

            return $this->withText($base, $text);
        }

        if ($ext === 'pdf') {
            $sidecar = preg_replace('/\.pdf$/i', '.txt', $path);
            if (is_string($sidecar) && Storage::disk('public')->exists($sidecar)) {
                return $this->withText($base, (string) Storage::disk('public')->get($sidecar));
            }
            $base['reason'] = self::REASON_PDF_NO_EXTRACT;

            return $base;
        }

        $base['reason'] = self::REASON_BINARY;

        return $base;
    }

    /**
     * @param  array{
     *     path: string,
     *     name: string,
     *     ext: string,
     *     source: string,
     *     extractable: bool,
     *     reason: string|null,
     *     text: string,
     *     url: string
     * }  $base
     * @return array{
     *     path: string,
     *     name: string,
     *     ext: string,
     *     source: string,
     *     extractable: bool,
     *     reason: string|null,
     *     text: string,
     *     url: string
     * }
     */
    private function withText(array $base, string $raw): array
    {
        $text = trim(str_replace("\0", '', $raw));
        if ($text === '') {
            $base['reason'] = self::REASON_EMPTY;

            return $base;
        }
        $base['extractable'] = true;
        $base['text'] = $text;

        return $base;
    }

    private function extractDocx(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }
        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! is_string($xml) || $xml === '') {
            return null;
        }
        $xml = str_replace(['</w:p>', '</w:tr>', '<w:tab/>'], "\n", $xml);
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<string>
     */
    private function splitSentences(string $text): array
    {
        $normalized = preg_replace('/\r\n?/', "\n", $text) ?? $text;
        $chunks = preg_split('/\n+|(?<=[.!?।])\s+/u', $normalized) ?: [];
        $out = [];
        foreach ($chunks as $chunk) {
            $line = trim((string) $chunk);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }
}
