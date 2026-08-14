<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\HindiAttachmentDrills;
use App\Services\HindiTranscriptDrillExtractor;
use App\Services\HindiTranscriptDrills;
use App\Support\HindiMySrsDeck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * H2443 + H2444 — student Hindi drills from transcript and/or lesson files.
 *
 * Either flag ON is enough to serve the page. Access is the same as the
 * H2441 playlist (paid/group/grant). Does not grant access.
 */
class HindiTranscriptDrillsController extends Controller
{
    public function show(
        Request $request,
        string $slug,
        int $lessonId,
        HindiTranscriptDrills $transcript,
        HindiAttachmentDrills $attachments,
    ): View {
        abort_unless($transcript->enabled() || $attachments->enabled(), 404);

        $user = $request->user();
        abort_unless($user !== null, 403);

        [$course, $lesson] = $this->resolveLesson($slug, $lessonId);
        abort_unless($transcript->isHindiLesson($lesson), 404);
        abort_unless($transcript->userCanAccess($user, $lesson), 403);

        [$items, $handouts] = $this->bundle($lesson, $transcript, $attachments);

        return view('student.programme.hindi-drills', [
            'course' => $course,
            'lesson' => $lesson,
            'items' => $items,
            'handouts' => $handouts,
            'attachmentsEnabled' => $attachments->enabled(),
            'transcriptEnabled' => $transcript->enabled(),
            'lessonUrl' => route('student.lesson', [$course->slug, $lesson->id]),
            'playlistEnabled' => (bool) config('features.hindi_programme_playlist', false),
            'srsDeckEnabled' => HindiMySrsDeck::enabled(),
        ]);
    }

    public function check(
        Request $request,
        string $slug,
        int $lessonId,
        HindiTranscriptDrills $transcript,
        HindiAttachmentDrills $attachments,
    ): JsonResponse {
        abort_unless($transcript->enabled() || $attachments->enabled(), 404);

        $user = $request->user();
        abort_unless($user !== null, 403);

        [, $lesson] = $this->resolveLesson($slug, $lessonId);
        abort_unless($transcript->isHindiLesson($lesson), 404);
        abort_unless($transcript->userCanAccess($user, $lesson), 403);

        $validated = $request->validate([
            'item_id' => ['required', 'string', 'max:80'],
            'answer' => ['required', 'string', 'max:80'],
        ]);

        $item = null;
        if ($transcript->enabled()) {
            $item = $transcript->findItem($lesson, $validated['item_id']);
        }
        if ($item === null && $attachments->enabled()) {
            $item = $attachments->findItem($lesson, $validated['item_id']);
        }
        abort_unless($item !== null, 404);

        $ok = HindiTranscriptDrillExtractor::answersMatch($item['answer'], $validated['answer']);

        return response()->json([
            'ok' => $ok,
            'item_id' => $item['id'],
            'correct_answer' => $ok ? null : $item['answer'],
        ]);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function bundle(
        Lesson $lesson,
        HindiTranscriptDrills $transcript,
        HindiAttachmentDrills $attachments,
    ): array {
        $items = [];
        if ($transcript->enabled()) {
            $items = $transcript->itemsFor($lesson);
        }
        $handouts = [];
        if ($attachments->enabled()) {
            $items = array_merge($items, $attachments->itemsFor($lesson));
            $handouts = $attachments->handoutsFor($lesson);
        }

        return [$items, $handouts];
    }

    /**
     * @return array{0: Course, 1: Lesson}
     */
    private function resolveLesson(string $slug, int $lessonId): array
    {
        $course = Course::resolveBySlugOrFail($slug);
        $lesson = Lesson::query()
            ->where('course_id', $course->id)
            ->findOrFail($lessonId);

        return [$course, $lesson];
    }
}
