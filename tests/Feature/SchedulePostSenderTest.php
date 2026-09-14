<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Services\Schedule\SchedulePostSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SchedulePostSenderTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function flag_off_means_nothing_is_sent(): void
    {
        Queue::fake();

        $group = Group::factory()->create(['telegram_chat_id' => '-100123']);
        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $group->id]);

        $sent = app(SchedulePostSender::class)->sendForGroup($group);

        $this->assertNull($sent);
        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_sends_and_remembers_hash(): void
    {
        config(['features.schedule_full_post' => true]);
        Queue::fake();

        $group = Group::factory()->create(['telegram_chat_id' => '-100123']);
        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $group->id]);

        $sent = app(SchedulePostSender::class)->sendForGroup($group);

        $this->assertNotNull($sent);
        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
        $this->assertDatabaseHas('schedule_posts', ['group_id' => $group->id]);

        // Тот же текст — повторной отправки нет (hash-память, не только guard).
        $again = app(SchedulePostSender::class)->sendForGroup($group);
        $this->assertNull($again);
        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);

        // Расписание изменилось (перенос даты) — пост уходит заново.
        Schedule::where('group_id', $group->id)->first()->update(['start' => Carbon::parse('2026-03-14 11:00')]);
        $afterMove = app(SchedulePostSender::class)->sendForGroup($group);
        $this->assertNotNull($afterMove);
        Queue::assertPushed(SendTelegramChatMessageJob::class, 2);

        // force обходит hash-память.
        $forced = app(SchedulePostSender::class)->sendForGroup($group, force: true);
        $this->assertNotNull($forced);
        Queue::assertPushed(SendTelegramChatMessageJob::class, 3);
    }

    /** @test */
    public function group_without_chat_id_is_skipped(): void
    {
        config(['features.schedule_full_post' => true]);
        Queue::fake();

        $group = Group::factory()->create(['telegram_chat_id' => null]);
        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $group->id]);

        $this->assertNull(app(SchedulePostSender::class)->sendForGroup($group));
        Queue::assertNothingPushed();
    }

    /** @test */
    public function sweep_picks_groups_with_recently_changed_schedules(): void
    {
        config(['features.schedule_full_post' => true]);
        Queue::fake();

        $changed = Group::factory()->create(['telegram_chat_id' => '-1001']);
        $old = Group::factory()->create(['telegram_chat_id' => '-1002']);
        $noChat = Group::factory()->create(['telegram_chat_id' => null]);

        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $changed->id]);
        Schedule::create(['title' => 'B', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $old->id]);
        Schedule::create(['title' => 'C', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $noChat->id]);

        // Старая группа ничего не меняла (updated_at давно).
        Schedule::where('group_id', $old->id)->update(['updated_at' => now()->subDays(3)]);

        $groups = app(SchedulePostSender::class)->changedGroups(24);

        $ids = $groups->pluck('id')->all();
        $this->assertContains($changed->id, $ids);
        $this->assertNotContains($old->id, $ids);
        $this->assertNotContains($noChat->id, $ids);

        // Свип отправляет только изменившимся.
        foreach ($groups as $g) {
            app(SchedulePostSender::class)->sendForGroup($g);
        }
        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
    }

    /** @test */
    public function soft_deleted_schedule_counts_as_change(): void
    {
        config(['features.schedule_full_post' => true]);

        $group = Group::factory()->create(['telegram_chat_id' => '-1001']);
        $schedule = Schedule::create(['title' => 'A', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $group->id]);
        Schedule::where('group_id', $group->id)->update(['updated_at' => now()->subDays(2)]);

        $schedule->delete(); // soft delete: updated_at обновится

        $this->assertContains($group->id, app(SchedulePostSender::class)->changedGroups(24)->pluck('id')->all());
    }

    /** @test */
    public function send_for_course_reports_counts(): void
    {
        config(['features.schedule_full_post' => true]);
        Queue::fake();

        $course = Course::factory()->create();
        $g1 = Group::factory()->create(['telegram_chat_id' => '-1001']);
        $g2 = Group::factory()->create(['telegram_chat_id' => null]);
        $course->groups()->attach([$g1->id, $g2->id]);

        Schedule::create(['title' => 'A', 'start' => Carbon::parse('2026-03-07 11:00'), 'group_id' => $g1->id]);

        $result = app(SchedulePostSender::class)->sendForCourse($course);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
    }
}
