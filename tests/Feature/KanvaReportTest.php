<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Services\Schedule\WeeklyFinishReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanvaReportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function kochergina_group_reports_cursor_lag_and_canvas_labels(): void
    {
        $course = Course::factory()->create(['title' => 'Грамматика по Кочергиной гр.60', 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create(['name' => 'Гр.60']);
        $course->groups()->attach($group->id);

        $student = User::factory()->create(['name' => 'Иванова Анна']);
        $group->users()->attach($student->id);

        // 4 прошедших занятия: записи уроков с канвой (читка 1..4).
        foreach ([1, 2, 3, 4] as $i) {
            $day = now()->subDays(21 - $i * 2);
            Schedule::create(['title' => 'S'.$i, 'start' => $day->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]);
            Lesson::create([
                'title' => 'Кочергина '.$i.' (читка)',
                'course_id' => $course->id,
                'lesson_date' => $day->format('Y-m-d H:i:s'),
            ]);
        }
        Schedule::create(['title' => 'Future', 'start' => now()->addDays(3)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]);

        // Студент был на последнем (4-я читка).
        WebinarAttendance::create([
            'schedule_id' => Schedule::where('title', 'S4')->first()->id,
            'user_id' => $student->id,
            'zoom_participant_uuid' => 'u1',
            'name' => 'И',
            'email' => 'i@t.test',
            'joined_at' => now()->subDays(13),
            'duration_seconds' => 3600,
        ]);

        $report = WeeklyFinishReport::build();

        $this->assertCount(1, $report);
        $row = $report[0];
        $this->assertSame('kochergina', $row['canvasFamily']);
        $this->assertSame(4, $row['canvasCursor']);
        $this->assertSame(40, $row['canvasTotal']);

        $chunks = WeeklyFinishReport::telegramChunks($report);
        $this->assertStringContainsString('Канва: урок 4/40', $chunks[0]);
        // Строка студента: канва-предмет отдельной шкалой.
        $this->assertStringContainsString('Иванова Анна — 4-е занятие', $chunks[0]);
        $this->assertStringContainsString('· Кочергина 4 (читка)', $chunks[0]);
        // Ссылка на ViewUser.
        $this->assertStringContainsString('href="http://localhost/admin/users/'.$student->id.'"', $chunks[0]);
    }

    /** @test */
    public function non_canvas_course_has_empty_canvas_block(): void
    {
        $course = Course::factory()->create(['title' => 'Философия', 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        Schedule::create(['title' => 'A', 'start' => now()->subDays(14)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]);
        Schedule::create(['title' => 'B', 'start' => now()->addDays(7)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]);

        $report = WeeklyFinishReport::build();

        $this->assertSame(0, $report[0]['canvasTotal']);
        $this->assertSame('', $report[0]['canvasFamily']);
    }
}
