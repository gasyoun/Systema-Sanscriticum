<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * H2444 — Hindi drills from files already on the lesson.
 *
 * Reuses HindiTranscriptDrillExtractor. Flag default OFF.
 * Does not scrape Telegram, does not OCR PDFs, does not grant access.
 */
final class HindiAttachmentDrills
{
    public function __construct(
        private readonly HindiAttachmentTextExtractor $files,
        private readonly HindiTranscriptDrillExtractor $extractor,
        private readonly HindiTranscriptDrills $transcript,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('features.hindi_attachment_drills', false);
    }

    public function isHindiLesson(Lesson $lesson): bool
    {
        return $this->transcript->isHindiLesson($lesson);
    }

    public function userCanAccess(User $user, Lesson $lesson): bool
    {
        return $this->transcript->userCanAccess($user, $lesson);
    }

    /**
     * @return list<array{
     *     id: string,
     *     type: string,
     *     prompt: string,
     *     sentence: string,
     *     answer: string,
     *     choices: list<string>|null,
     *     start: float,
     *     source: string
     * }>
     */
    public function itemsFor(Lesson $lesson): array
    {
        $listed = $this->files->listedPaths($lesson);
        if ($listed === []) {
            return [];
        }

        $stamp = [];
        foreach ($listed as $row) {
            $path = $row['path'];
            $mtime = Storage::disk('public')->exists($path)
                ? Storage::disk('public')->lastModified($path)
                : 0;
            $sidecar = preg_replace('/\.pdf$/i', '.txt', $path);
            $sidecarMtime = is_string($sidecar) && Storage::disk('public')->exists($sidecar)
                ? Storage::disk('public')->lastModified($sidecar)
                : 0;
            $stamp[] = $path.':'.$mtime.':'.$sidecarMtime;
        }
        $cacheKey = 'hindi_attachment_drills:v1:'.$lesson->id.':'.md5(implode('|', $stamp));

        return Cache::rememberForever($cacheKey, function () use ($lesson): array {
            $sentences = $this->files->sentencesFrom($lesson);
            $items = $this->extractor->extract($sentences);

            return array_map(static function (array $item): array {
                $item['source'] = 'attachment';
                $item['id'] = preg_replace('/^htd-/', 'had-', $item['id']) ?? $item['id'];

                return $item;
            }, $items);
        });
    }

    public function hasItems(Lesson $lesson): bool
    {
        return $this->itemsFor($lesson) !== [];
    }

    public function hasPracticePath(Lesson $lesson): bool
    {
        return $this->hasItems($lesson) || $this->handoutsFor($lesson) !== [];
    }

    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     prompt: string,
     *     sentence: string,
     *     answer: string,
     *     choices: list<string>|null,
     *     start: float,
     *     source?: string
     * }|null
     */
    public function findItem(Lesson $lesson, string $itemId): ?array
    {
        foreach ($this->itemsFor($lesson) as $item) {
            if ($item['id'] === $itemId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return list<array{
     *     path: string,
     *     name: string,
     *     ext: string,
     *     source: string,
     *     extractable: bool,
     *     reason: string|null,
     *     url: string
     * }>
     */
    public function handoutsFor(Lesson $lesson): array
    {
        $out = [];
        foreach ($this->files->filesFor($lesson) as $file) {
            unset($file['text']);
            $out[] = $file;
        }

        return $out;
    }
}
