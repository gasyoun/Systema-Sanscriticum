<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Support\TranscriptParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * H2443 — Hindi transcript drills (flag default OFF).
 *
 * Derives practice items from Lesson.transcript_file via TranscriptParser.
 * Access is reused from HindiProgrammePlaylist (same unlock rules as the
 * playlist / cabinet). Does not write payments or grants.
 */
final class HindiTranscriptDrills
{
    public function __construct(
        private readonly HindiTranscriptDrillExtractor $extractor,
        private readonly HindiProgrammePlaylist $playlist,
        private readonly ProgrammeShellGraph $graph,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('features.hindi_transcript_drills', false);
    }

    public function isHindiLesson(Lesson $lesson): bool
    {
        $course = $lesson->relationLoaded('course')
            ? $lesson->course
            : Course::query()->find($lesson->course_id);

        return $course instanceof Course && $this->isHindiShell($course);
    }

    public function isHindiShell(Course $course): bool
    {
        return $this->graph->orderedShells('hindi')->contains(
            static fn (Course $shell): bool => (int) $shell->id === (int) $course->id,
        );
    }

    public function userCanAccess(User $user, Lesson $lesson): bool
    {
        return $this->playlist->canAccessLesson($user, $lesson);
    }

    /**
     * @return list<array{
     *     id: string,
     *     type: string,
     *     prompt: string,
     *     sentence: string,
     *     answer: string,
     *     lemma: string,
     *     choices: list<string>|null,
     *     start: float
     * }>
     */
    public function itemsFor(Lesson $lesson): array
    {
        $path = $lesson->transcript_file;
        if (! is_string($path) || $path === '' || ! Storage::disk('public')->exists($path)) {
            return [];
        }

        $mtime = Storage::disk('public')->lastModified($path);
        $cacheKey = 'hindi_transcript_drills:v5:'.md5($path).':'.$mtime;

        return Cache::rememberForever($cacheKey, function () use ($path): array {
            $raw = Storage::disk('public')->get($path);
            $data = json_decode((string) $raw, true);
            $source = is_array($data) ? (string) data_get($data, 'metadata.source', '') : '';
            // YouTube ru-orig is Russian classroom ASR: Hindi lands as
            // Cyrillic/Tamil/English junk. Keep the file for the player.
            if ($source === 'youtube-auto-ru-orig') {
                return [];
            }

            $sentences = TranscriptParser::sentencesFromPublicFile($path);

            return $this->extractor->extract($sentences);
        });
    }

    public function hasItems(Lesson $lesson): bool
    {
        return $this->itemsFor($lesson) !== [];
    }

    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     prompt: string,
     *     sentence: string,
     *     answer: string,
     *     lemma: string,
     *     choices: list<string>|null,
     *     start: float
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
}
