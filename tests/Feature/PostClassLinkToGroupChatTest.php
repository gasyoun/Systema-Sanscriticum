<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostClassLinkToGroupChatTest extends TestCase
{
    use RefreshDatabase;

    private function enable(int $lead = 30): void
    {
        config(['features.class_link_autopost' => true]);
        MarketingSetting::create([
            'class_link_autopost_enabled' => true,
            'class_link_autopost_lead_minutes' => $lead,
        ]);
    }

    public function test_posts_link_to_group_chat_once_and_dedups(): void
    {
        Queue::fake();
        $this->enable();

        $group = Group::create(['name' => 'Группа 42', 'telegram_chat_id' => '-1001234567890']);
        $schedule = Schedule::create([
            'title' => 'Йога-сутры',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
            'zoom_join_url' => 'https://us02web.zoom.us/j/8807/?pwd=abc',
        ]);

        $this->artisan('classes:post-group-link')->assertSuccessful();

        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
        Queue::assertPushed(SendTelegramChatMessageJob::class, fn (SendTelegramChatMessageJob $job): bool => $job->chatId === '-1001234567890'
            && str_contains($job->text, 'us02web.zoom.us/j/8807')
            && str_contains($job->text, 'Йога-сутры'));

        $this->assertNotNull($schedule->fresh()->group_link_posted_at);

        // Повторный прогон не постит второй раз (дедуп).
        $this->artisan('classes:post-group-link')->assertSuccessful();
        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
    }

    public function test_falls_back_to_course_zoom_link(): void
    {
        Queue::fake();
        $this->enable();

        $course = Course::create(['title' => 'Аюрведа', 'slug' => 'ayurveda-test', 'zoom_link' => 'https://us02web.zoom.us/j/999']);
        $group = Group::create(['name' => 'Аюрведа-1', 'telegram_chat_id' => '-100999']);
        Schedule::create([
            'title' => 'Занятие 1',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
            'course_id' => $course->id,
        ]);

        $this->artisan('classes:post-group-link')->assertSuccessful();
        Queue::assertPushed(SendTelegramChatMessageJob::class, fn (SendTelegramChatMessageJob $job): bool => str_contains($job->text, 'us02web.zoom.us/j/999'));
    }

    public function test_skips_when_disabled(): void
    {
        Queue::fake();
        config(['features.class_link_autopost' => true]);
        MarketingSetting::create(['class_link_autopost_enabled' => false]);

        $group = Group::create(['name' => 'G', 'telegram_chat_id' => '-100']);
        Schedule::create([
            'title' => 'X', 'start' => now()->addMinutes(10),
            'group_id' => $group->id, 'zoom_join_url' => 'https://zoom.us/j/1',
        ]);

        $this->artisan('classes:post-group-link')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_skips_when_env_killswitch_off(): void
    {
        Queue::fake();
        config(['features.class_link_autopost' => false]);
        MarketingSetting::create(['class_link_autopost_enabled' => true]);

        $group = Group::create(['name' => 'G', 'telegram_chat_id' => '-100']);
        Schedule::create([
            'title' => 'X', 'start' => now()->addMinutes(10),
            'group_id' => $group->id, 'zoom_join_url' => 'https://zoom.us/j/1',
        ]);

        $this->artisan('classes:post-group-link')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    /**
     * Дубль-гвардия (диагноз 26-08-2026): zapisi:remind-classes уже отправил
     * «Скоро занятие» в этот же чат (T-60 против наших T-15) — второй пост
     * от другого бота студенты читают как повтор. Не шлём и не помечаем.
     */
    public function test_skips_schedule_already_reminded_by_zapisi_bot(): void
    {
        Queue::fake();
        $this->enable();

        $group = Group::create(['name' => 'Группа 7', 'telegram_chat_id' => '-100777']);
        $schedule = Schedule::create([
            'title' => 'Занятие 7',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
            'zoom_join_url' => 'https://zoom.us/j/7',
            'zapisi_reminded_at' => now()->subMinutes(45),
        ]);

        $this->artisan('classes:post-group-link')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($schedule->fresh()->group_link_posted_at);
    }

    public function test_skips_group_without_chat_id_and_leaves_unmarked(): void
    {
        Queue::fake();
        $this->enable();

        $group = Group::create(['name' => 'Без чата']); // нет telegram_chat_id
        $schedule = Schedule::create([
            'title' => 'X', 'start' => now()->addMinutes(10),
            'group_id' => $group->id, 'zoom_join_url' => 'https://zoom.us/j/1',
        ]);

        $this->artisan('classes:post-group-link')->assertSuccessful();
        Queue::assertNothingPushed();
        // Не помечаем отправленным — чтобы после заполнения чата ссылка ушла.
        $this->assertNull($schedule->fresh()->group_link_posted_at);
    }

    public function test_skips_schedule_outside_lead_window(): void
    {
        Queue::fake();
        $this->enable(15);

        $group = Group::create(['name' => 'G', 'telegram_chat_id' => '-100']);
        Schedule::create([
            'title' => 'Позже', 'start' => now()->addHours(3),
            'group_id' => $group->id, 'zoom_join_url' => 'https://zoom.us/j/1',
        ]);

        $this->artisan('classes:post-group-link')->assertSuccessful();
        Queue::assertNothingPushed();
    }
}
