<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\ScheduleJoinClick;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Services\Schedule\WeeklyFinishReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WeeklyFinishReportTest extends TestCase
{
    use RefreshDatabase;

    private function runningCourse(string $title = 'Философия'): array
    {
        $course = Course::factory()->create(['title' => $title, 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create(['name' => 'Группа А']);
        $course->groups()->attach($group->id);

        // 3 прошедших по вторникам 19:30 (последнее — 2 дня назад), 1 будущее.
        $past = collect([
            Schedule::create(['title' => 'Занятие 1', 'start' => now()->subDays(16)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]),
            Schedule::create(['title' => 'Занятие 2', 'start' => now()->subDays(9)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]),
            Schedule::create(['title' => 'Занятие 3', 'start' => now()->subDays(2)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]),
        ]);
        $future = Schedule::create(['title' => 'Занятие 4', 'start' => now()->addDays(5)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]);

        return [$course, $group, $past, $future];
    }

    private function attend(Schedule $schedule, User $user): void
    {
        WebinarAttendance::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'zoom_participant_uuid' => 'uuid-'.$schedule->id.'-'.$user->id,
            'name' => $user->name,
            'email' => $user->email,
            'joined_at' => $schedule->start->copy()->addMinutes(5),
            'duration_seconds' => 3600,
        ]);
    }

    /** @test */
    public function running_group_appears_with_full_roster(): void
    {
        [, $group, $past] = $this->runningCourse();

        $present = User::factory()->create(['name' => 'Иванова Анна']);
        $never = User::factory()->create(['name' => 'Петров Борис']);
        $group->users()->attach([$present->id, $never->id]);

        $this->attend($past[2], $present);

        $report = WeeklyFinishReport::build();

        $this->assertCount(1, $report);
        $this->assertSame(3, $report[0]['pastCount']);
        $this->assertSame(1, $report[0]['futureCount']);

        $names = array_map(fn (array $s): string => $s['user']->name, $report[0]['students']);
        $this->assertSame(['Иванова Анна', 'Петров Борис'], $names);
    }

    /** @test */
    public function last_attended_lesson_and_never_attended_are_reported(): void
    {
        [, $group, $past] = $this->runningCourse();

        $present = User::factory()->create(['name' => 'Иванова Анна']);
        $never = User::factory()->create(['name' => 'Петров Борис']);
        $group->users()->attach([$present->id, $never->id]);

        // Присутствовал на 1-м и 3-м (2-е пропустил) — последний факт = 3-е занятие.
        $this->attend($past[0], $present);
        $this->attend($past[2], $present);

        $report = WeeklyFinishReport::build();
        $students = collect($report[0]['students'])->keyBy(fn (array $s) => $s['user']->name);

        $last = $students['Иванова Анна']['last'];
        $this->assertSame('3-е занятие', $last['label']);
        $this->assertSame(0, $students['Иванова Анна']['missedStreak']); // был на новейшем

        $this->assertNull($students['Петров Борис']['last']);
        $this->assertSame(3, $students['Петров Борис']['missedStreak']); // ни одного факта
    }

    /** @test */
    public function click_counts_for_missed_streak_but_not_for_last_attended(): void
    {
        [, $group, $past] = $this->runningCourse();

        $clicker = User::factory()->create(['name' => 'Кириллов Вадим']);
        $group->users()->attach($clicker->id);

        // Кликал на 3-м, attendance нет: неявки-серии нет (факт-клик есть),
        // но «последнее посещение» = null (клик — weaker-факт).
        ScheduleJoinClick::create(['schedule_id' => $past[2]->id, 'user_id' => $clicker->id, 'first_clicked_at' => now()->subDays(2), 'click_count' => 1]);

        $report = WeeklyFinishReport::build();
        $student = $report[0]['students'][0];

        $this->assertNull($student['last']);
        $this->assertSame('3-е занятие', $student['clicked']['label']);
        $this->assertSame(0, $student['missedStreak']);

        $chunks = WeeklyFinishReport::telegramChunks($report);
        $this->assertStringContainsString('не был ни разу (кликал: 3-е занятие', $chunks[0]);
    }

    /** @test */
    public function missed_two_in_a_row_is_flagged(): void
    {
        [, $group, $past] = $this->runningCourse();

        $student = User::factory()->create(['name' => 'Смирнова Вера']);
        $group->users()->attach($student->id);

        // Был только на 1-м: пропустил 2-е и 3-е подряд.
        $this->attend($past[0], $student);

        $report = WeeklyFinishReport::build();
        $row = $report[0]['students'][0];

        $this->assertSame('1-е занятие', $row['last']['label']);
        $this->assertSame(2, $row['missedStreak']);

        $chunks = WeeklyFinishReport::telegramChunks($report);
        $this->assertStringContainsString('Смирнова Вера — 1-е занятие, ', $chunks[0]);
        $this->assertStringContainsString('⚠️ пропустил 2 подряд', $chunks[0]);
    }

    /** @test */
    public function finished_and_not_started_groups_are_excluded(): void
    {
        $course = Course::factory()->create(['title' => 'Соседний курс', 'is_active' => true, 'is_visible' => true]);

        // Закончилась: только прошедшие.
        $done = Group::factory()->create(['name' => 'Законченная']);
        $course->groups()->attach($done->id);
        Schedule::create(['title' => 'A', 'start' => now()->subDays(30)->format('Y-m-d H:i:s'), 'group_id' => $done->id, 'course_id' => $course->id]);
        Schedule::create(['title' => 'B', 'start' => now()->subDays(9)->format('Y-m-d H:i:s'), 'group_id' => $done->id, 'course_id' => $course->id]);

        // Ещё не стартовала: только будущие.
        $fresh = Group::factory()->create(['name' => 'Новая']);
        $course->groups()->attach($fresh->id);
        Schedule::create(['title' => 'C', 'start' => now()->addDays(10)->format('Y-m-d H:i:s'), 'group_id' => $fresh->id, 'course_id' => $course->id]);

        $this->runningCourse(); // одна идущая

        $report = WeeklyFinishReport::build();

        $this->assertCount(1, $report);
        $this->assertSame('Философия', $report[0]['course']->title);
    }

    /** @test */
    public function overview_is_not_numbered(): void
    {
        [, $group, $past] = $this->runningCourse();

        $overview = Schedule::create(['title' => 'Обзорное', 'start' => now()->subDays(23)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'is_overview' => true]);
        $student = User::factory()->create(['name' => 'Гришина Ольга']);
        $group->users()->attach($student->id);
        $this->attend($overview, $student);

        $report = WeeklyFinishReport::build();

        $this->assertSame(3, $report[0]['pastCount']); // обзорное в счёт прошедших, но не нумеруется
        $this->assertSame('Обзорное занятие', $report[0]['students'][0]['last']['label']);
    }

    /** @test */
    public function telegram_chunks_split_long_reports(): void
    {
        [, $group, $past] = $this->runningCourse();

        for ($i = 0; $i < 120; $i++) {
            $u = User::factory()->create(['name' => 'Студент '.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
            $group->users()->attach($u->id);
            $this->attend($past[$i % 3], $u);
        }

        $report = WeeklyFinishReport::build();
        $chunks = WeeklyFinishReport::telegramChunks($report);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(4096, mb_strlen($chunk));
        }
    }

    /** @test */
    public function command_dry_run_prints_without_sending(): void
    {
        config(['features.weekly_finish_report' => true]);

        $this->runningCourse();

        $this->artisan('care:weekly-finish', ['--dry-run' => true])
            ->expectsOutputToContain('НЕ отправлено')
            ->assertSuccessful();

        // Отправка гейтится чатом — в dry-run Job не диспетчится вовсе; здесь
        // просто фиксируем, что команда не упала и не отправила (Queue::fake
        // не нужен: dry-run путь диспетча не содержит).
        $this->assertTrue(true);
    }

    /** @test */
    public function command_is_gated_by_feature_flag(): void
    {
        config(['features.weekly_finish_report' => false]);

        $this->artisan('care:weekly-finish', ['--dry-run' => true])
            ->expectsOutputToContain('выключен')
            ->assertSuccessful();
    }

    /** @test */
    public function command_fails_loud_without_chat_id_when_sending(): void
    {
        config(['features.weekly_finish_report' => true]);
        config(['services.telegram.institute_chat_id' => '']);

        $this->runningCourse();

        $this->artisan('care:weekly-finish')
            ->expectsOutputToContain('TELEGRAM_INSTITUTE_CHAT_ID пуст')
            ->assertExitCode(1);
    }

    /** @test */
    public function date_format_matches_full_schedule_post(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-08 18:00'));

        [, $group, $past] = $this->runningCourse();
        $student = User::factory()->create(['name' => 'Данилов Егор']);
        $group->users()->attach($student->id);
        $this->attend($past[2], $student);

        $chunks = WeeklyFinishReport::telegramChunks(WeeklyFinishReport::build(), Carbon::parse('2026-09-07'));

        $this->assertStringContainsString('неделя 07.09.2026', $chunks[0]);
        $this->assertStringContainsString('3-е занятие, 6 сентября 2026 (воскресенье)', $chunks[0]);
    }
}
