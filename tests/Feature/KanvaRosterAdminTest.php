<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\AttendanceDashboard;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanvaRosterAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function dashboard_roster_carries_students_not_the_post(): void
    {
        // H4495 (MG 09-09): ростер студентов живёт ТОЛЬКО в админке.
        $course = Course::factory()->create(['title' => 'Грамматика по Кочергиной гр.60', 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create(['name' => 'гр.60']);
        $course->groups()->attach($group->id);
        $student = User::factory()->create(['name' => 'Иванова Анна']);
        $group->users()->attach($student->id);

        foreach ([1, 2, 3, 4] as $i) {
            $day = now()->subDays(21 - $i * 2);
            Schedule::create(['title' => 'S'.$i, 'start' => $day->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]);
            Lesson::create(['title' => 'Кочергина '.$i.' (читка)', 'course_id' => $course->id, 'lesson_date' => $day->format('Y-m-d H:i:s')]);
        }
        Schedule::create(['title' => 'Future', 'start' => now()->addDays(3)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]);

        WebinarAttendance::create([
            'schedule_id' => Schedule::where('title', 'S4')->first()->id,
            'user_id' => $student->id,
            'zoom_participant_uuid' => 'u1',
            'name' => 'И',
            'email' => 'i@t.test',
            'joined_at' => now()->subDays(13),
            'duration_seconds' => 3600,
        ]);

        $rows = (new AttendanceDashboard)->canvasRoster();

        $this->assertCount(1, $rows);
        $this->assertSame('гр.60', $rows[0]['group']);
        $student = $rows[0]['students'][0];
        $this->assertSame('Иванова Анна', $student['name']);
        $this->assertStringContainsString('4-е занятие', $student['last']);
        $this->assertSame('Кочергина 4 (читка)', $student['canvas']);
        $this->assertSame(0, $student['missed']);
    }
}
