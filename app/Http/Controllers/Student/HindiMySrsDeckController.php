<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\HindiAttachmentDrills;
use App\Services\HindiProgrammePlaylist;
use App\Services\HindiTranscriptDrills;
use App\Support\HindiMySrsDeck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * H2445 — add a Hindi drill lemma to the student's private «Мой хинди» deck.
 *
 * Client posts item_id or lesson_id only. Card text is read from the
 * transcript extractor server-side (same forgery-proofing as H2111).
 * Flag OFF → 404. Access is the same as the H2441 playlist. Does not
 * grant access and does not flip `srs.enabled`.
 */
class HindiMySrsDeckController extends Controller
{
    public function addItem(
        Request $request,
        string $slug,
        int $lessonId,
        HindiTranscriptDrills $drills,
        HindiAttachmentDrills $attachments,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless(HindiMySrsDeck::enabled() || app(HindiProgrammePlaylist::class)->teachesHindi($user), 404);

        [$course, $lesson] = $this->resolveLesson($slug, $lessonId);
        $this->authorizeHindiLesson($user, $lesson, $drills);

        $validated = $request->validate([
            'item_id' => ['required', 'string', 'max:80'],
        ]);

        $item = $drills->findItem($lesson, $validated['item_id'])
            ?? $attachments->findItem($lesson, $validated['item_id']);
        abort_unless($item !== null, 404);

        $status = HindiMySrsDeck::addItem($user, $lesson, $course, $item);

        return back()
            ->with('hindi_srs_status', $status)
            ->with('hindi_srs_word', HindiMySrsDeck::lemmaFromItem($item));
    }

    public function addLesson(
        Request $request,
        HindiTranscriptDrills $drills,
        HindiAttachmentDrills $attachments,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless(HindiMySrsDeck::enabled() || app(HindiProgrammePlaylist::class)->teachesHindi($user), 404);
        abort_unless((bool) config('features.hindi_programme_playlist', false), 404);

        $validated = $request->validate([
            'lesson_id' => ['required', 'integer', 'min:1'],
        ]);

        $lesson = Lesson::query()->with('course')->findOrFail((int) $validated['lesson_id']);
        $course = $lesson->course;
        abort_unless($course instanceof Course, 404);
        $this->authorizeHindiLesson($user, $lesson, $drills);

        $items = array_merge($drills->itemsFor($lesson), $attachments->itemsFor($lesson));
        if ($items === []) {
            return back()->with('hindi_srs_status', 'no_items');
        }

        $added = 0;
        $duplicate = 0;
        foreach ($items as $item) {
            $status = HindiMySrsDeck::addItem($user, $lesson, $course, $item);
            if ($status === 'added') {
                $added++;
            } elseif ($status === 'duplicate') {
                $duplicate++;
            }
        }

        $status = $added > 0 ? 'added_batch' : 'duplicate';

        return back()
            ->with('hindi_srs_status', $status)
            ->with('hindi_srs_count', $added)
            ->with('hindi_srs_duplicate', $duplicate);
    }

    private function authorizeHindiLesson(
        User $user,
        Lesson $lesson,
        HindiTranscriptDrills $drills,
    ): void {
        abort_unless($drills->isHindiLesson($lesson), 404);
        abort_unless($drills->userCanAccess($user, $lesson), 403);
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
