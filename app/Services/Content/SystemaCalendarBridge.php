<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use App\Models\LectureClip;
use App\Services\MonthlyScheduleDigest;
use Illuminate\Support\Carbon;

/**
 * Bridges live Systema signals (free lecture clips, upcoming class schedule)
 * into ContentCalendarSlot rows (H1566, content calendar W3). Only points at
 * existing artifacts — never cuts clips or posts to VK itself (ffmpeg/upload
 * stays n8n per H1452; C2).
 */
final class SystemaCalendarBridge
{
    public function __construct(
        private readonly MonthlyScheduleDigest $scheduleDigest = new MonthlyScheduleDigest,
    ) {}

    /**
     * Fill empty `clip_tease` slots for {year}-{month} with free LectureClips
     * that don't yet back a slot. Skip-review default (D12): Systema-sourced
     * content is not NEW copy, so a filled slot goes straight to `scheduled`
     * — same pattern as W2's evergreen fill.
     *
     * @return array{filled: int, remaining_empty: int, candidates_available: int}
     */
    public function bridgeClips(int $year, int $month): array
    {
        $slots = ContentCalendarSlot::query()
            ->forMonth($year, $month)
            ->where('slot_type', ContentCalendarSlot::TYPE_CLIP_TEASE)
            ->where('status', ContentCalendarSlot::STATUS_EMPTY)
            ->orderBy('slot_date')
            ->get();

        if ($slots->isEmpty()) {
            return ['filled' => 0, 'remaining_empty' => 0, 'candidates_available' => 0];
        }

        $usedClipIds = ContentCalendarSlot::query()
            ->where('source_kind', 'lecture_clip')
            ->whereNotNull('source_ref')
            ->pluck('source_ref')
            ->map(fn (string $ref): int => (int) $ref)
            ->all();

        $candidates = ContentCandidate::query()
            ->ofType(ContentCandidate::TYPE_CLIP)
            ->where('status', ContentCandidate::STATUS_ACCEPTED)
            ->whereNull('calendar_slot_id')
            ->whereNotNull('lecture_clip_id')
            ->whereNotIn('lecture_clip_id', $usedClipIds)
            ->with('lectureClip')
            ->orderBy('id')
            ->get()
            ->filter(fn (ContentCandidate $candidate): bool => $candidate->lectureClip?->is_free === true)
            ->values();

        $filled = 0;
        foreach ($slots as $slot) {
            $candidate = $candidates->shift();
            if ($candidate === null) {
                break;
            }

            $clip = $candidate->lectureClip;
            $link = $this->clipLink($clip);

            $slot->forceFill([
                'title' => 'Клип: '.$clip->title,
                'body' => $candidate->body ?: $clip->title,
                'source_kind' => 'lecture_clip',
                'source_ref' => (string) $clip->id,
                'meta' => array_merge($slot->meta ?? [], [
                    'lesson_id' => $clip->lesson_id,
                    'lecture_clip_id' => $clip->id,
                    'link' => $link,
                ]),
            ]);
            $slot->markKept();

            $candidate->forceFill([
                'calendar_slot_id' => $slot->id,
                'status' => ContentCandidate::STATUS_SCHEDULED,
                'meta' => array_merge($candidate->meta ?? [], ['link' => $link]),
            ])->save();

            $filled++;
        }

        return [
            'filled' => $filled,
            'remaining_empty' => $slots->count() - $filled,
            'candidates_available' => $candidates->count(),
        ];
    }

    private function clipLink(LectureClip $clip): ?string
    {
        if ($clip->vk_owner_id && $clip->vk_video_id) {
            return sprintf('https://vk.com/video%s_%s', $clip->vk_owner_id, $clip->vk_video_id);
        }

        return null;
    }

    /**
     * Create (idempotently) one monthly `event` digest slot for {year}-{month}
     * out of the existing `MonthlyScheduleDigest` (`schedule:post-monthly`'s
     * live poster) — reused rather than re-derived so the calendar digest can
     * never disagree with the live one about which courses are running
     * (course active/visible + block-intersects-month filter, teacher,
     * per-course schedule text, course URL all come from there; prior art,
     * do not rebuild). `MonthlyScheduleDigest` is "current month only" by
     * design (`Carbon::now()`, matching its production monthly-cron use) —
     * bridging a non-current target month is out of W3 scope and no-ops
     * (explicit default, D22). Dedupe per month via
     * source_kind=schedule_digest + source_ref=YYYY-MM — a monthly digest,
     * not per-class-change tracking (roadmap's separate `schedule_note` row;
     * deferred — `Schedule` carries no diff primitive yet and it isn't
     * required by W3's DoD).
     *
     * @return array{created: bool, course_count: int}
     */
    public function bridgeScheduleDigest(int $year, int $month): array
    {
        $ref = sprintf('%04d-%02d', $year, $month);

        $exists = ContentCalendarSlot::query()
            ->where('source_kind', 'schedule_digest')
            ->where('source_ref', $ref)
            ->exists();

        if ($exists) {
            return ['created' => false, 'course_count' => 0];
        }

        $now = Carbon::now();
        if ((int) $now->year !== $year || (int) $now->month !== $month) {
            return ['created' => false, 'course_count' => 0];
        }

        $courses = $this->scheduleDigest->courses();
        if ($courses === []) {
            return ['created' => false, 'course_count' => 0];
        }

        $titles = array_column($courses, 'title');

        $slot = ContentCalendarSlot::create([
            'channel' => ContentCalendarSlot::CHANNEL_VK_WALL,
            'slot_date' => $now->copy()->startOfMonth()->toDateString(),
            'slot_type' => ContentCalendarSlot::TYPE_EVENT,
            'status' => ContentCalendarSlot::STATUS_DRAFT,
            'title' => 'Расписание месяца: '.implode(', ', $titles),
            'body' => $this->scheduleDigest->textVk(),
            'source_kind' => 'schedule_digest',
            'source_ref' => $ref,
            'meta' => [
                'course_count' => count($courses),
                'courses' => $titles,
            ],
        ]);

        $slot->markKept();

        return ['created' => true, 'course_count' => count($courses)];
    }
}
