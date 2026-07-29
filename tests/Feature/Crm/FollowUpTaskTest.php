<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Deal;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\User;
use App\Services\WorkQueueReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GC-C3 задачи менеджеру (H1836). Покрывает:
 *  (a) создание задачи от сделки и её закрытие;
 *  (b) пятый бакет кокпита — попадание/непопадание ПО ДАТЕ и по done_at;
 *  (c) флаг crm_follow_up_tasks — дефолт И наблюдаемое поведение при OFF;
 *  (d) регрессия: crm_reminders / RemindLeadsForFollowup не изменились.
 */
class FollowUpTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deploy-рубильник ВЫКЛ по умолчанию — тесты бакета включают его явно
        // (зеркало DealTest/SegmentTest).
        config(['features.crm_follow_up_tasks' => true]);
    }

    private function report(): WorkQueueReport
    {
        return app(WorkQueueReport::class);
    }

    // ---------------------------------------------------------------- (a)

    /** @test */
    public function a_task_is_created_from_a_deal_and_closes_only_via_done_at(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $deal = Deal::factory()->create();

        $task = FollowUpTask::create([
            'deal_id' => $deal->id,
            'assigned_to' => $manager->id,
            'type' => FollowUpTask::TYPE_CALL,
            'due_at' => now(),
        ]);

        $this->assertTrue($task->deal->is($deal));
        $this->assertTrue($task->assignee->is($manager));
        $this->assertFalse($task->isDone());
        $this->assertSame(1, FollowUpTask::query()->open()->count());

        $task->markDone();

        $this->assertTrue($task->fresh()->isDone());
        $this->assertSame(0, FollowUpTask::query()->open()->count());
    }

    /** @test */
    public function marking_an_already_done_task_does_not_move_the_original_stamp(): void
    {
        $task = FollowUpTask::factory()->done()->create();
        $first = $task->done_at;

        $this->travel(2)->hours();
        $task->markDone();

        $this->assertEquals($first, $task->fresh()->done_at);
    }

    /** @test */
    public function deleting_a_deal_takes_its_tasks_with_it(): void
    {
        $deal = Deal::factory()->create();
        FollowUpTask::factory()->create(['deal_id' => $deal->id]);

        $deal->delete();

        $this->assertSame(0, FollowUpTask::query()->count());
    }

    // ---------------------------------------------------------------- (b)

    /** @test */
    public function the_due_bucket_includes_today_and_overdue_tasks(): void
    {
        FollowUpTask::factory()->create(['due_at' => now()]);
        FollowUpTask::factory()->overdue()->create();

        $this->assertCount(2, $this->report()->followUpTasksDue());
        $this->assertSame(2, $this->report()->counts()['follow_ups']);
    }

    /** @test */
    public function the_due_bucket_excludes_future_tasks(): void
    {
        FollowUpTask::factory()->upcoming()->create();

        $this->assertCount(0, $this->report()->followUpTasksDue());
        $this->assertSame(0, $this->report()->counts()['follow_ups']);
    }

    /** @test */
    public function the_due_bucket_excludes_tasks_already_done(): void
    {
        FollowUpTask::factory()->overdue()->done()->create();

        $this->assertCount(0, $this->report()->followUpTasksDue());
    }

    /** @test */
    public function a_task_due_later_today_is_already_in_the_bucket(): void
    {
        // Сравнение по ДАТЕ, а не по времени: задача на сегодня видна с утра.
        $this->travelTo(now()->startOfDay()->addHours(9));
        FollowUpTask::factory()->create(['due_at' => now()->startOfDay()->addHours(18)]);

        $this->assertCount(1, $this->report()->followUpTasksDue());
    }

    /** @test */
    public function a_closed_deal_does_not_close_its_task(): void
    {
        $deal = Deal::factory()->create(['closed_at' => now(), 'closed_reason' => Deal::REASON_LOST]);
        FollowUpTask::factory()->create(['deal_id' => $deal->id, 'due_at' => now()]);

        $this->assertCount(1, $this->report()->followUpTasksDue(), 'закрытость сделки — не признак закрытия задачи');
    }

    /** @test */
    public function the_other_four_buckets_are_untouched_by_the_fifth(): void
    {
        Lead::create([
            'name' => 'Лид Сегодня',
            'contact' => 'tg:@lead',
            'status' => 'new',
            'next_contact_at' => now()->toDateString(),
        ]);
        FollowUpTask::factory()->create(['due_at' => now()]);

        $counts = $this->report()->counts();

        $this->assertSame(1, $counts['leads']);
        $this->assertSame(1, $counts['follow_ups']);
        $this->assertSame(0, $counts['promises']);
        $this->assertSame(0, $counts['support']);
    }

    // ---------------------------------------------------------------- (c)

    /** @test */
    public function the_bucket_is_empty_while_the_flag_is_off(): void
    {
        config(['features.crm_follow_up_tasks' => false]);
        FollowUpTask::factory()->overdue()->create();

        $this->assertCount(0, $this->report()->followUpTasksDue());
        $this->assertSame(0, $this->report()->counts()['follow_ups']);
    }

    // ---------------------------------------------------------------- (d)

    /** @test */
    public function the_follow_up_task_flag_does_not_touch_crm_reminders(): void
    {
        // Задачи ВКЛ, рассылка напоминаний ВЫКЛ — команда обязана остаться no-op.
        config(['features.crm_reminders' => false]);
        Queue::fake();

        $manager = User::factory()->create(['role' => 'manager', 'telegram_id' => '12345']);
        $lead = Lead::create([
            'name' => 'Лид на контакт',
            'contact' => 'tg:@lead',
            'status' => 'new',
            'assigned_to' => $manager->id,
            'next_contact_at' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('leads:remind-followup')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($lead->fresh()->reminded_at, 'выключённый crm_reminders обязан оставаться no-op');
    }

    /** @test */
    public function crm_reminders_still_pings_managers_when_it_is_on(): void
    {
        // Обратная половина регрессии: задачи ВЫКЛ, напоминания ВКЛ —
        // прежнее поведение команды сохранено байт-в-байт.
        config(['features.crm_reminders' => true, 'features.crm_follow_up_tasks' => false]);
        Queue::fake();

        $manager = User::factory()->create(['role' => 'manager', 'telegram_id' => '12345']);
        $lead = Lead::create([
            'name' => 'Лид на контакт',
            'contact' => 'tg:@lead',
            'status' => 'new',
            'assigned_to' => $manager->id,
            'next_contact_at' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('leads:remind-followup')->assertSuccessful();

        Queue::assertPushed(\App\Jobs\SendTelegramMessageJob::class);
        $this->assertNotNull($lead->fresh()->reminded_at);
    }
}
