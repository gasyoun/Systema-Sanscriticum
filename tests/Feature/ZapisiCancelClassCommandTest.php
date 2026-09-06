<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\TelegramChatPost;
use App\Services\Telegram\CancelClassCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H4199: reply-команда админа «Отмена занятия» на пост-напоминание zapisi-бота.
 * Каскад +7 дней через ScheduleMover, анонс об отмене тем же ботом,
 * whitelist из marketing_settings.zapisi_cancel_admin_ids, беззвучные отказы.
 */
class ZapisiCancelClassCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_ID = 111;

    private const CHAT_ID = '-100123';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('marketing_setting.singleton');
    }

    private function seedAdmin(int $adminId = self::ADMIN_ID): void
    {
        MarketingSetting::create(['zapisi_cancel_admin_ids' => (string) $adminId]);
        Cache::forget('marketing_setting.singleton');
    }

    /** Занятие через час + следующая неделя цепочки + маппинг «пост 777 → занятие». */
    private function mappedSchedule(string $title = 'Рецитация сутр Патанджали, вс 10:00, 2026 (#13, 06.09.26)'): Schedule
    {
        $group = Group::create(['name' => 'Рецитация | вс 10:00 | 2026', 'telegram_chat_id' => self::CHAT_ID]);
        $schedule = Schedule::create([
            'title' => $title,
            'start' => now()->addHour(),
            'end' => now()->addHour()->addMinutes(90),
            'group_id' => $group->id,
            'link' => 'https://zoom.us/j/x',
        ]);
        Schedule::create([
            'title' => 'Рецитация сутр Патанджали, вс 10:00, 2026 (#14, 13.09.26)',
            'start' => now()->addHour()->addWeek(),
            'end' => now()->addHour()->addWeek()->addMinutes(90),
            'group_id' => $group->id,
            'link' => 'https://zoom.us/j/x',
        ]);
        TelegramChatPost::create([
            'schedule_id' => $schedule->id,
            'chat_id' => self::CHAT_ID,
            'message_id' => 777,
            'kind' => TelegramChatPost::KIND_ZAPISI_REMINDER,
            'posted_at' => now(),
        ]);

        return $schedule;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function message(array $overrides = []): array
    {
        return array_merge([
            'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
            'message_id' => 888,
            'from' => ['id' => self::ADMIN_ID],
            'text' => 'Отмена занятия',
            'reply_to_message' => ['message_id' => 777],
        ], $overrides);
    }

    public function test_valid_reply_cancels_with_week_cascade_and_posts_notice(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->once()->andReturn(true);
        $this->seedAdmin();
        $schedule = $this->mappedSchedule();

        app(CancelClassCommandService::class)->handle($this->message());

        $fresh = $schedule->fresh();
        $this->assertSame(
            now()->addHour()->addWeek()->format('Y-m-d H:i'),
            $fresh->start->format('Y-m-d H:i'),
        );
        $this->assertSame(
            now()->addHour()->addWeek()->addMinutes(90)->format('Y-m-d H:i'),
            $fresh->end->format('Y-m-d H:i'),
        );

        // Анонс — единственное сообщение бота в чат, с датой следующего занятия.
        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        Queue::assertPushed(SendZapisiBotMessageJob::class, function (SendZapisiBotMessageJob $job): bool {
            return $job->chatId === self::CHAT_ID
                && str_contains($job->text, 'Занятие отменено')
                // Хвостовая дата «(#13, 06.09.26)» из титула вырезана.
                && str_contains($job->text, 'Рецитация сутр Патанджали, вс 10:00, 2026»')
                && str_contains($job->text, 'Следующее занятие');
        });
    }

    public function test_sender_outside_whitelist_is_ignored_silently(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->never();
        $this->seedAdmin();
        $schedule = $this->mappedSchedule();

        app(CancelClassCommandService::class)->handle($this->message([
            'from' => ['id' => 999],
        ]));

        Queue::assertNothingPushed();
        $this->assertSame(now()->addHour()->format('Y-m-d H:i'), $schedule->fresh()->start->format('Y-m-d H:i'));
    }

    public function test_empty_whitelist_disables_command(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->never();
        $schedule = $this->mappedSchedule();

        app(CancelClassCommandService::class)->handle($this->message());

        Queue::assertNothingPushed();
        $this->assertSame(now()->addHour()->format('Y-m-d H:i'), $schedule->fresh()->start->format('Y-m-d H:i'));
    }

    public function test_non_command_text_is_ignored(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->never();
        $this->seedAdmin();
        $schedule = $this->mappedSchedule();

        app(CancelClassCommandService::class)->handle($this->message(['text' => 'Спасибо, придём!']));

        Queue::assertNothingPushed();
        $this->assertSame(now()->addHour()->format('Y-m-d H:i'), $schedule->fresh()->start->format('Y-m-d H:i'));
    }

    public function test_reply_without_mapped_post_is_ignored(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->never();
        $this->seedAdmin();
        $schedule = $this->mappedSchedule();

        app(CancelClassCommandService::class)->handle($this->message([
            'reply_to_message' => ['message_id' => 555],
        ]));

        Queue::assertNothingPushed();
        $this->assertSame(now()->addHour()->format('Y-m-d H:i'), $schedule->fresh()->start->format('Y-m-d H:i'));
    }

    public function test_past_lesson_refuses_cascade(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->once()->andReturn(true);
        $this->seedAdmin();
        $schedule = $this->mappedSchedule();
        $schedule->update(['start' => now()->subHour()]);

        app(CancelClassCommandService::class)->handle($this->message());

        Queue::assertNothingPushed();
        $this->assertSame(now()->subHour()->format('Y-m-d H:i'), $schedule->fresh()->start->format('Y-m-d H:i'));
    }

    /** Повторная команда на тот же пост подавляется клеймом — цепочка не уедет на +2 недели. */
    public function test_duplicate_command_on_same_post_is_suppressed(): void
    {
        Queue::fake();
        Redis::shouldReceive('set')->twice()->andReturn(true, false);
        $this->seedAdmin();
        $schedule = $this->mappedSchedule();

        app(CancelClassCommandService::class)->handle($this->message());
        app(CancelClassCommandService::class)->handle($this->message());

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        $this->assertSame(
            now()->addHour()->addWeek()->format('Y-m-d H:i'),
            $schedule->fresh()->start->format('Y-m-d H:i'),
        );
    }

    /** Успешная отправка с scheduleId запоминает message_id → маппинг для reply-команды. */
    public function test_send_job_stores_mapping_when_schedule_id_given(): void
    {
        Redis::shouldReceive('set')->once()->andReturn(true);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]]),
        ]);
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN']);
        Cache::forget('marketing_setting.singleton');

        $group = Group::create(['name' => 'G', 'telegram_chat_id' => self::CHAT_ID]);
        $schedule = Schedule::create([
            'title' => 'X', 'start' => now()->addHour(), 'group_id' => $group->id,
        ]);

        (new SendZapisiBotMessageJob(self::CHAT_ID, 'текст', $schedule->id, TelegramChatPost::KIND_ZAPISI_REMINDER))->handle();

        $this->assertDatabaseHas('telegram_chat_posts', [
            'chat_id' => self::CHAT_ID,
            'message_id' => 4242,
            'schedule_id' => $schedule->id,
            'kind' => TelegramChatPost::KIND_ZAPISI_REMINDER,
        ]);
    }

    /** Без scheduleId (обычные отправки) маппинг не пишется — поведение прежнее. */
    public function test_send_job_without_schedule_id_stores_nothing(): void
    {
        Redis::shouldReceive('set')->once()->andReturn(true);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4243]]),
        ]);
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN']);
        Cache::forget('marketing_setting.singleton');

        (new SendZapisiBotMessageJob(self::CHAT_ID, 'текст'))->handle();

        $this->assertDatabaseCount('telegram_chat_posts', 0);
    }

    /** zapisi:remind-classes передаёт в джобу scheduleId + kind — маппинг появляется сам. */
    public function test_remind_command_dispatches_job_with_schedule_id_and_kind(): void
    {
        Queue::fake();
        config(['features.telegram_zapisi_bot' => true]);
        MarketingSetting::create(['zapisi_reminder_lead_minutes' => 60]);
        Cache::forget('marketing_setting.singleton');

        $group = Group::create(['name' => 'Группа 61', 'telegram_chat_id' => self::CHAT_ID]);
        $schedule = Schedule::create([
            'title' => 'Грамматика',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
            'zoom_join_url' => 'https://zoom.us/j/61',
        ]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        Queue::assertPushed(SendZapisiBotMessageJob::class, fn (SendZapisiBotMessageJob $job): bool => $job->scheduleId === $schedule->id
            && $job->kind === TelegramChatPost::KIND_ZAPISI_REMINDER);
    }
}
