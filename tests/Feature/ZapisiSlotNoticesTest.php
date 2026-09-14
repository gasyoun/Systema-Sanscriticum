<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * MG 08-09, zapisi:slot-notices:
 *  A. «Сегодня занятия нет» — в обычный слот группы, когда занятие перенесено
 *     (строки на сегодня нет), а будущее занятие существует.
 *  B. Оплата за блок — после каждого 4-го занятия: до какого числа оплата и
 *     что неоплатившим закрывается кабинет (остаются игры/бесплатные вебинары/оплата).
 */
class ZapisiSlotNoticesTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100123';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['features.telegram_zapisi_bot' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function groupWithChat(): Group
    {
        return Group::create(['name' => 'Рецитация | вт 15:00 | 2026', 'telegram_chat_id' => self::CHAT_ID]);
    }

    /** 08.09.2026 — вторник; обычный слот группы — вторник 15:00 (вчера через неделю назад). */
    private function seedWeeklyChain(int $groupId): void
    {
        Schedule::create(['title' => 'Рецитация (#12, 01.09.26)', 'start' => '2026-09-01 15:00:00', 'end' => '2026-09-01 16:00:00', 'group_id' => $groupId]);
        Schedule::create(['title' => 'Рецитация (#13, 15.09.26)', 'start' => '2026-09-15 15:00:00', 'end' => '2026-09-15 16:00:00', 'group_id' => $groupId]);
        Schedule::create(['title' => 'Рецитация (#14, 22.09.26)', 'start' => '2026-09-22 15:00:00', 'end' => '2026-09-22 16:00:00', 'group_id' => $groupId]);
    }

    public function test_no_lesson_today_notice_in_usual_slot(): void
    {
        Carbon::setTestNow('2026-09-08 14:02:00');
        Redis::shouldReceive('set')->once()->andReturn(true);
        $group = $this->groupWithChat();
        $this->seedWeeklyChain($group->id);

        $this->artisan('zapisi:slot-notices')->assertSuccessful();

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        Queue::assertPushed(SendZapisiBotMessageJob::class, function (SendZapisiBotMessageJob $job): bool {
            return $job->chatId === self::CHAT_ID
                && str_contains($job->text, 'Сегодня занятия нет')
                && str_contains($job->text, '15.09.2026')
                && str_contains($job->text, '13-е из 14');
        });
    }

    public function test_no_notice_when_lesson_scheduled_today(): void
    {
        Carbon::setTestNow('2026-09-08 14:02:00');
        Redis::shouldReceive('set')->never();
        $group = $this->groupWithChat();
        $this->seedWeeklyChain($group->id);
        Schedule::create(['title' => 'Рецитация (#13, 08.09.26)', 'start' => '2026-09-08 15:00:00', 'end' => '2026-09-08 16:00:00', 'group_id' => $group->id]);

        $this->artisan('zapisi:slot-notices')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_payment_reminder_after_fourth_lesson_of_block(): void
    {
        Carbon::setTestNow('2026-09-08 15:05:00');
        Redis::shouldReceive('set')->once()->andReturn(true);
        $group = $this->groupWithChat();
        Schedule::create(['title' => 'Рецитация (#4, 08.09.26)', 'start' => '2026-09-08 14:00:00', 'end' => '2026-09-08 15:00:00', 'group_id' => $group->id]);
        Schedule::create(['title' => 'Рецитация (#5, 15.09.26)', 'start' => '2026-09-15 15:00:00', 'end' => '2026-09-15 16:30:00', 'group_id' => $group->id]);
        Schedule::create(['title' => 'Рецитация (#7, 29.09.26)', 'start' => '2026-09-29 15:00:00', 'end' => '2026-09-29 16:30:00', 'group_id' => $group->id]);

        $this->artisan('zapisi:slot-notices')->assertSuccessful();

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        Queue::assertPushed(SendZapisiBotMessageJob::class, function (SendZapisiBotMessageJob $job): bool {
            return $job->chatId === self::CHAT_ID
                && str_contains($job->text, 'Блок занятий завершён — 4-е из 7')
                && str_contains($job->text, '15.09.2026')
                && str_contains($job->text, 'игры, просмотр бесплатных вебинаров');
        });
    }

    public function test_no_payment_reminder_for_non_block_lesson(): void
    {
        Carbon::setTestNow('2026-09-08 15:05:00');
        Redis::shouldReceive('set')->never();
        $group = $this->groupWithChat();
        Schedule::create(['title' => 'Рецитация (#3, 08.09.26)', 'start' => '2026-09-08 14:00:00', 'end' => '2026-09-08 15:00:00', 'group_id' => $group->id]);
        Schedule::create(['title' => 'Рецитация (#4, 15.09.26)', 'start' => '2026-09-15 15:00:00', 'end' => '2026-09-15 16:30:00', 'group_id' => $group->id]);

        $this->artisan('zapisi:slot-notices')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_payment_reminder_sent_once_per_schedule(): void
    {
        Carbon::setTestNow('2026-09-08 15:05:00');
        Redis::shouldReceive('set')->twice()->andReturn(true, false);
        $group = $this->groupWithChat();
        Schedule::create(['title' => 'Рецитация (#4, 08.09.26)', 'start' => '2026-09-08 14:00:00', 'end' => '2026-09-08 15:00:00', 'group_id' => $group->id]);
        Schedule::create(['title' => 'Рецитация (#5, 15.09.26)', 'start' => '2026-09-15 15:00:00', 'end' => '2026-09-15 16:30:00', 'group_id' => $group->id]);

        $this->artisan('zapisi:slot-notices')->assertSuccessful();
        $this->artisan('zapisi:slot-notices')->assertSuccessful();

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
    }
}
