<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\CertificateMilestone;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MissingMilestoneDetectorTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.telegram.curators_chat_id' => '-1001234567890']);

        $this->course = Course::factory()->create();
        $this->group = Group::create(['name' => 'Поток', 'status' => 'active']);
        $this->course->groups()->attach($this->group->id);
    }

    private function lessons(int $count): void
    {
        foreach (range(1, $count) as $i) {
            Lesson::create([
                'title' => 'Занятие '.$i,
                'course_id' => $this->course->id,
                'group_id' => $this->group->id,
                'lesson_date' => today()->subDays($count - $i + 1),
            ]);
        }
    }

    /** @test */
    public function it_flags_courses_over_threshold_and_dedupes(): void
    {
        $this->lessons(12);

        $this->artisan('courses:detect-missing-milestones')->assertSuccessful();

        Queue::assertPushed(SendTelegramChatMessageJob::class, fn ($job) => str_contains($job->text, 'без вех сертификатов'));
        $this->assertNotNull($this->course->fresh()->milestones_nudge_sent_at);

        // Повторный прогон молчит (дедуп по штампу).
        $this->artisan('courses:detect-missing-milestones')->assertSuccessful();
        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
    }

    /** @test */
    public function it_stays_silent_below_threshold_or_with_active_milestones(): void
    {
        $this->lessons(11);
        $this->artisan('courses:detect-missing-milestones')->assertSuccessful();
        Queue::assertNothingPushed();

        // Достигли порога, но веха уже настроена — тоже тишина.
        Lesson::create([
            'title' => '12-е',
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'lesson_date' => today()->subDay(),
        ]);
        CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Итог',
            'trigger_type' => CertificateMilestone::TRIGGER_MANUAL,
        ]);
        $this->artisan('courses:detect-missing-milestones')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    /** @test */
    public function creating_a_milestone_resets_the_nudge_stamp(): void
    {
        $this->lessons(12);
        $this->artisan('courses:detect-missing-milestones')->assertSuccessful();
        $this->assertNotNull($this->course->fresh()->milestones_nudge_sent_at);

        CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Появилась веха',
            'trigger_type' => CertificateMilestone::TRIGGER_MANUAL,
        ]);

        $this->assertNull($this->course->fresh()->milestones_nudge_sent_at);
    }

    /** @test */
    public function the_gate_and_custom_threshold_are_respected(): void
    {
        MarketingSetting::create(['course_missing_milestones_notify_enabled' => false]);
        $this->lessons(20);
        $this->artisan('courses:detect-missing-milestones')->assertSuccessful();
        Queue::assertNothingPushed();

        MarketingSetting::query()->update([
            'course_missing_milestones_notify_enabled' => true,
            'course_missing_milestones_threshold' => 25,
        ]);
        MarketingSetting::flushCached();
        $this->artisan('courses:detect-missing-milestones')->assertSuccessful();
        Queue::assertNothingPushed(); // 20 < 25

        MarketingSetting::query()->update(['course_missing_milestones_threshold' => 20]);
        MarketingSetting::flushCached();
        $this->artisan('courses:detect-missing-milestones')->assertSuccessful();
        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
    }
}
