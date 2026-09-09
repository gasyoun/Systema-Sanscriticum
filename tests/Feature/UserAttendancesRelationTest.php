<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4460: $user->attendances() кидал BadMethodCallException (связи не было на
 * модели) — cabinet 500 у реальных userIds 5862/6804/6571/5836. Регрессия:
 * связь существует, питает канву H4435 в кабинете и на ViewUser.
 */
class UserAttendancesRelationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function user_has_attendances_relation(): void
    {
        $student = User::factory()->create();

        $this->assertTrue(method_exists(User::class, 'attendances'));
        $this->assertSame(0, $student->attendances()->count());
    }

    /** @test */
    public function attendances_relation_feeds_h4435_canvas_fact_lookup(): void
    {
        $course = Course::factory()->create(['title' => 'Грамматика по Кочергиной гр.60', 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create(['name' => 'Гр.60']);
        $course->groups()->attach($group->id);

        $student = User::factory()->create();
        $group->users()->attach($student->id);

        $pastDay = now()->subDays(5);
        $schedule = Schedule::create([
            'title' => 'S1',
            'start' => $pastDay->format('Y-m-d H:i:s'),
            'group_id' => $group->id,
            'course_id' => $course->id,
        ]);
        Lesson::create([
            'title' => 'Кочергина 1 (читка)',
            'course_id' => $course->id,
            'lesson_date' => $pastDay->format('Y-m-d H:i:s'),
        ]);

        WebinarAttendance::create([
            'schedule_id' => $schedule->id,
            'user_id' => $student->id,
            'zoom_participant_uuid' => 'h4460',
            'name' => 'H4460',
            'email' => 'h4460@t.test',
            'joined_at' => $pastDay,
            'duration_seconds' => 1800,
        ]);

        $fact = $student->attendances()
            ->whereIn('schedule_id', Schedule::where('group_id', $group->id)->pluck('id'))
            ->latest('created_at')->first();

        $this->assertNotNull($fact);
        $this->assertSame($schedule->id, $fact->schedule_id);
        $this->assertSame($student->id, $fact->user_id);
    }

    /** @test */
    public function attendances_relation_scopes_to_the_user(): void
    {
        $student = User::factory()->create();
        $other = User::factory()->create();
        $schedule = Schedule::create([
            'title' => 'S2',
            'start' => now()->subDay()->format('Y-m-d H:i:s'),
            'group_id' => Group::factory()->create()->id,
        ]);

        WebinarAttendance::create([
            'schedule_id' => $schedule->id,
            'user_id' => $other->id,
            'zoom_participant_uuid' => 'other',
            'name' => 'O',
            'email' => 'o@t.test',
            'joined_at' => now()->subDay(),
        ]);

        $this->assertSame(0, $student->attendances()->count());
        $this->assertSame(1, $other->attendances()->count());
    }
}
