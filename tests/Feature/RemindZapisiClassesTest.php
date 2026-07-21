<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * zapisi:remind-classes — напоминание о занятии в чат группы через @zapisi_ORSbot
 * прямо из расписания (Schedule → group.telegram_chat_id). Заменяет ручную
 * таблицу zapisi_class_schedules. Гейт telegram_zapisi_bot, дедуп zapisi_reminded_at.
 */
class RemindZapisiClassesTest extends TestCase
{
    use RefreshDatabase;

    private function enable(int $lead = 60): void
    {
        config(['features.telegram_zapisi_bot' => true]);
        MarketingSetting::create(['zapisi_reminder_lead_minutes' => $lead]);
    }

    public function test_reminds_group_chat_once_and_dedups(): void
    {
        Queue::fake();
        $this->enable();

        $group = Group::create(['name' => 'Группа 61', 'telegram_chat_id' => '-1001234567890']);
        $schedule = Schedule::create([
            'title' => 'Грамматика',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
        ]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        Queue::assertPushed(SendZapisiBotMessageJob::class, fn (SendZapisiBotMessageJob $job): bool => $job->chatId === '-1001234567890'
            && str_contains($job->text, 'Грамматика')
            && str_contains($job->text, 'Группа 61'));

        $this->assertNotNull($schedule->fresh()->zapisi_reminded_at);

        // Повторный прогон не шлёт второй раз (дедуп).
        $this->artisan('zapisi:remind-classes')->assertSuccessful();
        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
    }

    public function test_skips_when_flag_off(): void
    {
        Queue::fake();
        config(['features.telegram_zapisi_bot' => false]);

        $group = Group::create(['name' => 'G', 'telegram_chat_id' => '-100']);
        Schedule::create(['title' => 'X', 'start' => now()->addMinutes(10), 'group_id' => $group->id]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_skips_group_without_chat_id_and_leaves_unmarked(): void
    {
        Queue::fake();
        $this->enable();

        $group = Group::create(['name' => 'Без чата']); // нет telegram_chat_id
        $schedule = Schedule::create(['title' => 'X', 'start' => now()->addMinutes(10), 'group_id' => $group->id]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();
        Queue::assertNothingPushed();
        // Не помечаем — уйдёт, когда чат заполнят.
        $this->assertNull($schedule->fresh()->zapisi_reminded_at);
    }

    public function test_skips_schedule_outside_lead_window(): void
    {
        Queue::fake();
        $this->enable(60);

        $group = Group::create(['name' => 'G', 'telegram_chat_id' => '-100']);
        Schedule::create(['title' => 'Позже', 'start' => now()->addHours(3), 'group_id' => $group->id]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_rescheduling_class_rearms_reminder(): void
    {
        Queue::fake();
        $this->enable();

        $group = Group::create(['name' => 'G', 'telegram_chat_id' => '-100']);
        $schedule = Schedule::create([
            'title' => 'X', 'start' => now()->addMinutes(10), 'group_id' => $group->id,
        ]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();
        $this->assertNotNull($schedule->fresh()->zapisi_reminded_at);

        // Перенос времени сбрасывает метку — напоминание перевзведётся к новому старту.
        $schedule->update(['start' => now()->addMinutes(20)]);
        $this->assertNull($schedule->fresh()->zapisi_reminded_at);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();
        Queue::assertPushed(SendZapisiBotMessageJob::class, 2);
    }
}
