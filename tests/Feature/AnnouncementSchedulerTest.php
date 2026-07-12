<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Models\Announcement;
use App\Models\Group;
use App\Models\User;
use App\Services\AnnouncementDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Плановая отправка анонса (Announcement.scheduled_at, H816/S6):
 * announcements:dispatch-due рассылает только созревшие и ещё не
 * отправленные анонсы, идемпотентно (dispatched_at). Реюз
 * AnnouncementDispatcher, извлечённого из CreateAnnouncement::afterCreate().
 */
class AnnouncementSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function announcement(array $overrides = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'Тестовый анонс',
            'preview' => 'Превью',
            'content' => 'Текст анонса',
            'send_to_telegram' => true,
        ], $overrides));
    }

    /** @test */
    public function due_scheduled_announcement_is_dispatched(): void
    {
        Queue::fake();

        $student = User::factory()->create(['telegram_id' => 123]);
        $this->announcement(['scheduled_at' => now()->subMinute()]);

        $this->artisan('announcements:dispatch-due')->assertExitCode(0);

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($student));
    }

    /** @test */
    public function future_scheduled_announcement_is_not_dispatched_yet(): void
    {
        Queue::fake();

        User::factory()->create(['telegram_id' => 124]);
        $this->announcement(['scheduled_at' => now()->addDay()]);

        $this->artisan('announcements:dispatch-due')->assertExitCode(0);

        Queue::assertNotPushed(SendMessengerAlerts::class);
    }

    /** @test */
    public function already_dispatched_announcement_is_not_resent(): void
    {
        Queue::fake();

        User::factory()->create(['telegram_id' => 125]);
        $this->announcement(['scheduled_at' => now()->subDay(), 'dispatched_at' => now()->subHour()]);

        $this->artisan('announcements:dispatch-due')->assertExitCode(0);

        Queue::assertNotPushed(SendMessengerAlerts::class);
    }

    /** @test */
    public function unscheduled_announcement_is_ignored_by_the_command(): void
    {
        Queue::fake();

        User::factory()->create(['telegram_id' => 126]);
        $this->announcement(); // scheduled_at = null

        $this->artisan('announcements:dispatch-due')->assertExitCode(0);

        Queue::assertNotPushed(SendMessengerAlerts::class);
    }

    /** @test */
    public function dispatcher_marks_announcement_as_dispatched_and_is_idempotent(): void
    {
        Queue::fake();

        User::factory()->create(['telegram_id' => 127]);
        $announcement = $this->announcement();

        app(AnnouncementDispatcher::class)->dispatch($announcement);
        $announcement->refresh();

        $this->assertNotNull($announcement->dispatched_at);
        Queue::assertPushed(SendMessengerAlerts::class, 1);

        // Повторный вызов на уже отправленном — no-op (идемпотентность).
        app(AnnouncementDispatcher::class)->dispatch($announcement);
        Queue::assertPushed(SendMessengerAlerts::class, 1);
    }

    /** @test */
    public function dispatcher_scopes_recipients_to_target_courses_via_groups(): void
    {
        Queue::fake();

        $group = Group::create(['name' => 'Целевая группа']);
        $inGroup = User::factory()->create(['telegram_id' => 128]);
        $group->users()->attach($inGroup->id);
        $outsideGroup = User::factory()->create(['telegram_id' => 129]);

        $announcement = $this->announcement(['target_courses' => [$group->id]]);
        app(AnnouncementDispatcher::class)->dispatch($announcement);

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($inGroup));
        Queue::assertNotPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($outsideGroup));
    }
}
