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
    public const SOURCE_YOUTUBE_AUTO = 'youtube-auto-ru-orig';

    public const SOURCE_YOUTUBE_NOVA3 = 'deepgram-nova-3';

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

    public function youtubeNova3StudentVisible(): bool
    {
        return (bool) config('features.hindi_youtube_nova3_drills', false);
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
    public function itemsFor(Lesson $lesson, bool $includeYoutubeNova3 = false): array
    {
        $payload = $this->cachedPayload($lesson);
        if ($payload === null) {
            return [];
        }
        if ($payload['source'] === self::SOURCE_YOUTUBE_NOVA3
            && ! $this->youtubeNova3StudentVisible()
            && ! $includeYoutubeNova3) {
            return [];
        }

        return $payload['items'];
    }

    public function transcriptSource(Lesson $lesson): string
    {
        $payload = $this->cachedPayload($lesson);

        return is_array($payload) ? (string) $payload['source'] : '';
    }

    public function isYoutubeNova3(Lesson $lesson): bool
    {
        return $this->transcriptSource($lesson) === self::SOURCE_YOUTUBE_NOVA3;
    }

    public function hasItems(Lesson $lesson, bool $includeYoutubeNova3 = false): bool
    {
        return $this->itemsFor($lesson, $includeYoutubeNova3) !== [];
    }

    /**
     * First YouTube-re-ASR lesson per Hindi shell the teacher can open.
     * Empty for students. Live from transcripts, not a static list.
     *
     * @return list<array{
     *     lesson: Lesson,
     *     course: Course,
     *     shell_label: string,
     *     url: string,
     *     drills_url: string
     * }>
     */
    public function youtubeNova3ReviewRows(User $user): array
    {
        if (! $this->playlist->teachesHindi($user)) {
            return [];
        }

        $firstPerShell = [];
        foreach ($this->playlist->itemsFor($user) as $row) {
            $cid = (int) $row['course']->id;
            if (isset($firstPerShell[$cid])) {
                continue;
            }
            $firstPerShell[$cid] = $row;
        }

        $out = [];
        foreach ($firstPerShell as $row) {
            $lesson = $row['lesson'];
            if (! $this->isYoutubeNova3($lesson)) {
                continue;
            }
            if ($this->itemsFor($lesson, true) === []) {
                continue;
            }
            $out[] = [
                'lesson' => $lesson,
                'course' => $row['course'],
                'shell_label' => $row['shell_label'],
                'url' => $row['url'],
                'drills_url' => route('student.lesson.drills', [$row['course']->slug, $lesson->id]),
            ];
            if (count($out) >= 5) {
                break;
            }
        }

        return $out;
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
    public function findItem(Lesson $lesson, string $itemId, bool $includeYoutubeNova3 = false): ?array
    {
        foreach ($this->itemsFor($lesson, $includeYoutubeNova3) as $item) {
            if ($item['id'] === $itemId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array{source: string, items: list<array<string, mixed>>}|null
     */
    private function cachedPayload(Lesson $lesson): ?array
    {
        $path = $lesson->transcript_file;
        if (! is_string($path) || $path === '' || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $mtime = Storage::disk('public')->lastModified($path);
        $cacheKey = 'hindi_transcript_drills:v6:'.md5($path).':'.$mtime;

        return Cache::rememberForever($cacheKey, function () use ($path): array {
            $raw = Storage::disk('public')->get($path);
            $data = json_decode((string) $raw, true);
            $source = is_array($data) ? (string) data_get($data, 'metadata.source', '') : '';
            // YouTube ru-orig is Russian classroom ASR: Hindi lands as
            // Cyrillic/Tamil/English junk. Keep the file for the player.
            if ($source === self::SOURCE_YOUTUBE_AUTO) {
                return ['source' => $source, 'items' => []];
            }

            $sentences = TranscriptParser::sentencesFromPublicFile($path);

            return [
                'source' => $source,
                'items' => $this->extractor->extract($sentences),
            ];
        });
    }
}
