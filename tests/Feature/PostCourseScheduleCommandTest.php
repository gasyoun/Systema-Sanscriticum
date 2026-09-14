<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostCourseScheduleCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function flag_off_short_circuits_everything(): void
    {
        Queue::fake();

        $group = Group::factory()->create(['telegram_chat_id' => '-1001']);
        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $group->id]);

        $this->artisan('courses:post-schedule', ['courseId' => $group->id, '--due' => true])
            ->expectsOutputToContain('SCHEDULE_FULL_POST')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** @test */
    public function dry_run_prints_text_without_sending(): void
    {
        config(['features.schedule_full_post' => true]);
        Queue::fake();

        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $group->id]);

        $this->artisan('courses:post-schedule', ['courseId' => $course->id, '--dry' => true])
            ->expectsOutputToContain('1-е занятие: 7 марта 2026 (суббота), 11:00')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('schedule_posts', 0);
    }

    /** @test */
    public function sweep_sends_changed_and_skips_unchanged(): void
    {
        config(['features.schedule_full_post' => true]);
        Queue::fake();

        $group = Group::factory()->create(['telegram_chat_id' => '-1001']);
        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $group->id]);

        // Первый свип: отправка.
        $this->artisan('courses:post-schedule', ['--due' => true])->assertSuccessful();
        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);

        // Второй свип в тот же день: текст не изменился — тишина.
        $this->artisan('courses:post-schedule', ['--due' => true])->assertSuccessful();
        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
    }

    /** @test */
    public function without_args_and_due_fails_with_hint(): void
    {
        config(['features.schedule_full_post' => true]);

        $this->artisan('courses:post-schedule')
            ->expectsOutputToContain('--due')
            ->assertFailed();
    }
}
