<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\ZapisiClassSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendZapisiClassRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_reminder_once_and_dedups(): void
    {
        Queue::fake();
        config(['features.telegram_zapisi_bot' => true]);

        $schedule = ZapisiClassSchedule::create([
            'chat_id' => '-1009988',
            'starts_at' => now()->addMinutes(30),
            'message_template' => 'Занятие начнётся в {time}.',
        ]);

        $this->artisan('zapisi:send-reminders')->assertSuccessful();

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        Queue::assertPushed(SendZapisiBotMessageJob::class, fn (SendZapisiBotMessageJob $job): bool => $job->chatId === '-1009988'
            && str_contains($job->text, $schedule->fresh()->starts_at->format('H:i')));

        $this->assertNotNull($schedule->fresh()->sent_at);

        $this->artisan('zapisi:send-reminders')->assertSuccessful();
        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
    }

    public function test_skips_when_feature_flag_off(): void
    {
        Queue::fake();
        config(['features.telegram_zapisi_bot' => false]);

        ZapisiClassSchedule::create([
            'chat_id' => '-100',
            'starts_at' => now()->addMinutes(10),
            'message_template' => 'X',
        ]);

        $this->artisan('zapisi:send-reminders')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_skips_schedule_outside_lead_window(): void
    {
        Queue::fake();
        config(['features.telegram_zapisi_bot' => true]);

        ZapisiClassSchedule::create([
            'chat_id' => '-100',
            'starts_at' => now()->addHours(3),
            'message_template' => 'X',
        ]);

        $this->artisan('zapisi:send-reminders')->assertSuccessful();
        Queue::assertNothingPushed();
    }
}
