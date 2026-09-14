<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCalendarSlot;
use App\Models\StoryPost;
use App\Models\Teacher;
use App\Services\Content\TeacherStoryProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4313: teacher-spotlight + student-story slot types, 4-week program seed.
 * Source: Uprava custdev/TEACHERS_AND_STORIES_CONTENT_PROGRAM_07-09-2026.md.
 */
class TeacherStoryProgramSeedTest extends TestCase
{
    use RefreshDatabase;

    private const START = [2026, 9, 7]; // Monday

    public function test_seed_creates_8_teacher_spotlights_with_real_teacher_ids(): void
    {
        $this->seedProgram();

        $slots = ContentCalendarSlot::query()
            ->where('slot_type', ContentCalendarSlot::TYPE_TEACHER_SPOTLIGHT)
            ->get();

        $this->assertCount(8, $slots);

        // own-data parity: every seeded card carries a real teacher_id from §1 roster.
        $ids = $slots->map(fn ($s) => $s->meta['teacher_id'])->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $ids);

        // Real teachers actually exist in the teachers table (prod ID parity).
        foreach ($slots as $slot) {
            $this->assertNotNull(Teacher::query()->find($slot->meta['teacher_id']), 'teacher id '.$slot->meta['teacher_id'].' must exist');
            $this->assertStringContainsString('samskrtam.ru', $slot->meta['page_url']);
        }
    }

    public function test_seed_creates_4_student_story_holds(): void
    {
        $this->seedProgram();

        $slots = ContentCalendarSlot::query()
            ->where('slot_type', ContentCalendarSlot::TYPE_STUDENT_STORY)
            ->get();

        $this->assertCount(4, $slots);

        // Red line (program §4): no consent yet → HOLD, never scheduled, empty body.
        foreach ($slots as $slot) {
            $this->assertNotSame(ContentCalendarSlot::STATUS_SCHEDULED, $slot->status);
            $this->assertSame('hold', $slot->meta['consent']);
            $this->assertNull($slot->body);
        }
    }

    public function test_cadence_is_two_teachers_plus_one_story_per_week(): void
    {
        $this->seedProgram();

        $spotlights = ContentCalendarSlot::query()
            ->where('slot_type', ContentCalendarSlot::TYPE_TEACHER_SPOTLIGHT)
            ->orderBy('slot_date')
            ->get();
        $stories = ContentCalendarSlot::query()
            ->where('slot_type', ContentCalendarSlot::TYPE_STUDENT_STORY)
            ->orderBy('slot_date')
            ->get();

        // Weeks 0..3: Mon+Wed spotlights, Friday stories — 2 + 1 each week.
        for ($w = 0; $w < 4; $w++) {
            $weekPair = $spotlights->slice($w * 2, 2);
            $this->assertCount(2, $weekPair);
            $this->assertSame(1, $weekPair->first()->slot_date->dayOfWeekIso); // Mon
            $this->assertSame(3, $weekPair->last()->slot_date->dayOfWeekIso);  // Wed
            $this->assertSame(5, $stories[$w]->slot_date->dayOfWeekIso);       // Fri
        }
    }

    public function test_every_unit_mirrored_to_story_posts_channel_lane(): void
    {
        $this->seedProgram();

        $this->assertSame(12, StoryPost::query()->where('lane', StoryPost::LANE_CHANNEL)->count());
        $this->assertSame(0, StoryPost::query()->where('lane', StoryPost::LANE_PERSONA)->count());

        // Mirrors stay draft with no publish_at — publish is a human/editorial step.
        foreach (StoryPost::all() as $post) {
            $this->assertSame(StoryPost::STATUS_DRAFT, $post->status);
            $this->assertNull($post->publish_at);
        }
    }

    public function test_seed_is_idempotent(): void
    {
        $this->seedProgram();
        $second = $this->seedProgram();

        $this->assertSame(0, $second['created']);
        $this->assertSame(8, ContentCalendarSlot::query()->where('slot_type', ContentCalendarSlot::TYPE_TEACHER_SPOTLIGHT)->count());
        $this->assertSame(4, ContentCalendarSlot::query()->where('slot_type', ContentCalendarSlot::TYPE_STUDENT_STORY)->count());
        $this->assertSame(12, StoryPost::count());
    }

    public function test_artisan_noops_when_flag_off_without_force(): void
    {
        config(['features.content_calendar' => false]);

        $this->artisan('content:seed-teacher-story', ['start' => '2026-09-07'])
            ->assertSuccessful();

        $this->assertSame(0, ContentCalendarSlot::count());
        $this->assertSame(0, StoryPost::count());
    }

    public function test_artisan_seeds_with_force_flag(): void
    {
        config(['features.content_calendar' => false]);

        $this->artisan('content:seed-teacher-story', [
            'start' => '2026-09-07',
            '--force-flag' => true,
        ])->assertSuccessful();

        $this->assertSame(12, ContentCalendarSlot::count());
        $this->assertSame(12, StoryPost::count());
    }

    public function test_autopilot_flag_stays_false_by_default(): void
    {
        // Fail = autopilot enabled in commit (red line of the handoff).
        $this->assertSame(false, config('features.content_calendar_autopilot'));
    }

    private function seedProgram(): array
    {
        // Real roster IDs the seed references must exist (teachers table).
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $id) {
            Teacher::query()->create(['id' => $id, 'name' => 'Teacher '.$id, 'email' => "t{$id}@example.test"]);
        }

        $seeder = new TeacherStoryProgramSeeder;

        return $seeder->seed(...self::START);
    }
}
