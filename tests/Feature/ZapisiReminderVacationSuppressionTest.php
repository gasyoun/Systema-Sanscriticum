<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\RemindZapisiClasses;
use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H4253: напоминания @zapisi_ORSbot не уходят в отпускное окно — ни по
 * групповому флагу is_on_vacation (ранее пробел: напоминания флаг игнорировали),
 * ни по окну преподавателя. Пометка zapisi_reminded_at не ставится —
 * после снятия окна напоминание уйдёт.
 */
class ZapisiReminderVacationSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100888';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['features.telegram_zapisi_bot' => true]);
    }

    private function seedWorld(): array
    {
        $teacher = Teacher::create(['name' => 'Препод Отпуск']);
        $course = Course::create([
            'title' => 'Курс',
            'slug' => 'crs-'.substr(md5(uniqid('', true)), 0, 10),
            'teacher_id' => $teacher->id,
            'zoom_link' => 'https://zoom.us/j/course',
        ]);
        $group = Group::create(['name' => 'Группа', 'telegram_chat_id' => self::CHAT_ID]);
        $course->groups()->attach($group->id);
        $schedule = Schedule::create([
            'title' => 'Занятие',
            'start' => now()->addMinutes(30),
            'end' => now()->addMinutes(90),
            'group_id' => $group->id,
            'link' => 'https://zoom.us/j/x',
        ]);

        return [$teacher, $course, $group, $schedule];
    }

    public function test_group_flag_suppresses_reminder_without_marking(): void
    {
        [, , $group, $schedule] = $this->seedWorld();
        $group->forceFill(['is_on_vacation' => true])->save();

        $this->artisan(RemindZapisiClasses::class);

        Queue::assertNothingPushed();
        $this->assertNull($schedule->fresh()->zapisi_reminded_at);
    }

    public function test_teacher_window_suppresses_reminder(): void
    {
        [$teacher, , $group, $schedule] = $this->seedWorld();
        $teacher->forceFill([
            'on_vacation_from' => now()->subDay()->toDateString(),
            'on_vacation_until' => now()->addWeek()->toDateString(),
        ])->save();

        $this->artisan(RemindZapisiClasses::class);

        Queue::assertNothingPushed();
        $this->assertNull($schedule->fresh()->zapisi_reminded_at);
    }

    public function test_open_teacher_window_suppresses_reminder(): void
    {
        [$teacher, , $group, $schedule] = $this->seedWorld();
        $teacher->forceFill(['on_vacation_from' => now()->subDay()->toDateString()])->save();

        $this->artisan(RemindZapisiClasses::class);

        Queue::assertNothingPushed();
        $this->assertNull($schedule->fresh()->zapisi_reminded_at);
    }

    public function test_reminder_sends_after_window_ends(): void
    {
        [$teacher, , $group, $schedule] = $this->seedWorld();
        $teacher->forceFill([
            'on_vacation_from' => now()->subWeek()->toDateString(),
            'on_vacation_until' => now()->subDay()->toDateString(),
        ])->save();

        $this->artisan(RemindZapisiClasses::class);

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        $this->assertNotNull($schedule->fresh()->zapisi_reminded_at);
    }

    public function test_reminder_sends_without_any_vacation(): void
    {
        [, , , $schedule] = $this->seedWorld();

        $this->artisan(RemindZapisiClasses::class);

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        $this->assertNotNull($schedule->fresh()->zapisi_reminded_at);
    }
}
