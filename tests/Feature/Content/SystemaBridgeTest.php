<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\LectureClip;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Services\Content\SystemaCalendarBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Systema bridge (H1566, content calendar W3): free LectureClips + upcoming
 * class schedule -> calendar slots pointing at existing artifacts only.
 * C1: free clip -> slot with lesson/clip ref. C2: no ffmpeg/VK upload.
 */
class SystemaBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(): Lesson
    {
        return Lesson::factory()->for(Course::factory())->create();
    }

    private function emptyClipTeaseSlot(?string $date = null): ContentCalendarSlot
    {
        return ContentCalendarSlot::create([
            'channel' => ContentCalendarSlot::CHANNEL_VK_WALL,
            'slot_date' => $date ?? now()->startOfMonth()->addDays(4)->toDateString(),
            'slot_type' => ContentCalendarSlot::TYPE_CLIP_TEASE,
            'status' => ContentCalendarSlot::STATUS_EMPTY,
            'title' => 'Клип / мост Systema',
        ]);
    }

    /** Course running the current month per MonthlyScheduleDigest's own filter (block intersects month). */
    private function runningCourse(string $title = 'Санскрит для начинающих'): Course
    {
        $course = Course::factory()->create(['is_active' => true, 'is_visible' => true, 'title' => $title]);
        CourseBlock::factory()->for($course)
            ->withDates(now()->startOfMonth(), now()->endOfMonth())
            ->create(['number' => 1]);

        return $course;
    }

    public function test_bridge_fills_clip_tease_slot_from_free_clip(): void
    {
        $slot = $this->emptyClipTeaseSlot();
        $lesson = $this->lesson();
        $clip = LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Разбор шлоки',
            'vk_owner_id' => '-88831040',
            'vk_video_id' => '456239017',
            'is_free' => true,
        ]);

        $result = (new SystemaCalendarBridge)->bridgeClips((int) now()->year, (int) now()->month);

        $this->assertSame(1, $result['filled']);

        $slot->refresh();
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $slot->status);
        $this->assertSame('lecture_clip', $slot->source_kind);
        $this->assertSame((string) $clip->id, $slot->source_ref);
        $this->assertSame($lesson->id, $slot->meta['lesson_id']);
        $this->assertSame('https://vk.com/video-88831040_456239017', $slot->meta['link']);

        $candidate = ContentCandidate::where('lecture_clip_id', $clip->id)->firstOrFail();
        $this->assertSame($slot->id, $candidate->calendar_slot_id);
        $this->assertSame(ContentCandidate::STATUS_SCHEDULED, $candidate->status);
    }

    public function test_bridge_ignores_non_free_clips(): void
    {
        $this->emptyClipTeaseSlot();
        $lesson = $this->lesson();
        LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Не бесплатный клип',
            'is_free' => false,
        ]);

        $result = (new SystemaCalendarBridge)->bridgeClips((int) now()->year, (int) now()->month);

        $this->assertSame(0, $result['filled']);
    }

    public function test_bridge_skips_clip_already_used_in_another_slot(): void
    {
        $this->emptyClipTeaseSlot(now()->startOfMonth()->addDays(4)->toDateString());
        $second = $this->emptyClipTeaseSlot(now()->startOfMonth()->addDays(11)->toDateString());
        $lesson = $this->lesson();
        LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Единственный клип',
            'is_free' => true,
        ]);

        $bridge = new SystemaCalendarBridge;
        $bridge->bridgeClips((int) now()->year, (int) now()->month);
        $result = $bridge->bridgeClips((int) now()->year, (int) now()->month);

        $this->assertSame(0, $result['filled']);
        $second->refresh();
        $this->assertSame(ContentCalendarSlot::STATUS_EMPTY, $second->status);
    }

    public function test_bridge_noops_when_no_empty_clip_tease_slots(): void
    {
        $lesson = $this->lesson();
        LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Клип без слота',
            'is_free' => true,
        ]);

        $result = (new SystemaCalendarBridge)->bridgeClips((int) now()->year, (int) now()->month);

        $this->assertSame(0, $result['filled']);
        $this->assertSame(0, $result['remaining_empty']);
    }

    public function test_schedule_digest_creates_one_event_slot_reusing_monthly_schedule_digest(): void
    {
        $course = $this->runningCourse();
        Schedule::create([
            'title' => 'Занятие',
            'course_id' => $course->id,
            'start' => now()->startOfMonth()->addDays(2)->setTime(14, 0),
        ]);

        $result = (new SystemaCalendarBridge)->bridgeScheduleDigest((int) now()->year, (int) now()->month);

        $this->assertTrue($result['created']);
        $this->assertSame(1, $result['course_count']);

        $slot = ContentCalendarSlot::where('source_kind', 'schedule_digest')
            ->where('source_ref', now()->format('Y-m'))
            ->firstOrFail();
        $this->assertSame(ContentCalendarSlot::TYPE_EVENT, $slot->slot_type);
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $slot->status);
        // Body reuses MonthlyScheduleDigest::textVk() verbatim — same copy as the live poster.
        $this->assertStringContainsString('Санскрит для начинающих', $slot->body);
        $this->assertStringContainsString('ревнителей санскрита', $slot->body);
    }

    public function test_schedule_digest_excludes_hidden_course_same_as_live_poster(): void
    {
        $hidden = Course::factory()->create(['is_active' => true, 'is_visible' => false]);
        CourseBlock::factory()->for($hidden)
            ->withDates(now()->startOfMonth(), now()->endOfMonth())
            ->create(['number' => 1]);
        Schedule::create(['title' => 'Занятие', 'course_id' => $hidden->id, 'start' => now()->startOfMonth()->addDays(2)]);

        $result = (new SystemaCalendarBridge)->bridgeScheduleDigest((int) now()->year, (int) now()->month);

        $this->assertFalse($result['created']);
        $this->assertSame(0, ContentCalendarSlot::where('source_kind', 'schedule_digest')->count());
    }

    public function test_schedule_digest_is_idempotent(): void
    {
        $this->runningCourse();

        $bridge = new SystemaCalendarBridge;
        $bridge->bridgeScheduleDigest((int) now()->year, (int) now()->month);
        $result = $bridge->bridgeScheduleDigest((int) now()->year, (int) now()->month);

        $this->assertFalse($result['created']);
        $this->assertSame(1, ContentCalendarSlot::where('source_kind', 'schedule_digest')->count());
    }

    public function test_schedule_digest_noops_when_no_courses_running(): void
    {
        $result = (new SystemaCalendarBridge)->bridgeScheduleDigest((int) now()->year, (int) now()->month);

        $this->assertFalse($result['created']);
        $this->assertSame(0, ContentCalendarSlot::where('source_kind', 'schedule_digest')->count());
    }

    public function test_schedule_digest_noops_for_non_current_month(): void
    {
        $this->runningCourse();

        $other = now()->addMonthsNoOverflow(2);
        $result = (new SystemaCalendarBridge)->bridgeScheduleDigest((int) $other->year, (int) $other->month);

        $this->assertFalse($result['created']);
        $this->assertSame(0, ContentCalendarSlot::where('source_kind', 'schedule_digest')->count());
    }

    public function test_bridge_makes_no_http_calls(): void
    {
        Http::fake();

        $this->emptyClipTeaseSlot();
        $lesson = $this->lesson();
        LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Клип',
            'is_free' => true,
        ]);
        $this->runningCourse();

        $bridge = new SystemaCalendarBridge;
        $bridge->bridgeClips((int) now()->year, (int) now()->month);
        $bridge->bridgeScheduleDigest((int) now()->year, (int) now()->month);

        Http::assertNothingSent();
    }

    public function test_command_noops_when_flag_off_without_force(): void
    {
        config(['features.content_calendar' => false]);
        $this->emptyClipTeaseSlot();

        $this->artisan('content:bridge-systema', ['month' => now()->format('Y-m')])->assertSuccessful();

        $this->assertSame(ContentCalendarSlot::STATUS_EMPTY, ContentCalendarSlot::first()->status);
    }

    public function test_command_bridges_when_forced(): void
    {
        $this->emptyClipTeaseSlot();
        $lesson = $this->lesson();
        LectureClip::create([
            'lesson_id' => $lesson->id,
            'start_seconds' => 0,
            'end_seconds' => 90,
            'title' => 'Клип',
            'is_free' => true,
        ]);

        $this->artisan('content:bridge-systema', ['month' => now()->format('Y-m'), '--force-flag' => true])
            ->assertSuccessful();

        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, ContentCalendarSlot::first()->status);
    }

    public function test_command_rejects_bad_month_format(): void
    {
        $this->artisan('content:bridge-systema', ['month' => '2026-9', '--force-flag' => true])
            ->assertFailed();
    }
}
