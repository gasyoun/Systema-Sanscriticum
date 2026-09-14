<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Services\Schedule\TextbookScale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4641: student /dvaram должен рендерить канва-блок (H4435) без фатала.
 *
 * Регрессия 10-13.09.2026 (Sev-1, 43 ошибки / 9 студентов, ~3 дня): inline
 * `App\Models\Schedule::where(...)` внутри namespaced-файла резолвился PHP
 * ОТНОСИТЕЛЬНО текущего namespace → `App\Http\Controllers\App\Models\Schedule`
 * → fatal "Class not found" → 500 всем студентам с курсом-учебником.
 * Этот тест заводит ровно ту факстуру, при которой kanva-курсор
 * (StudentController, блок H4435) реально исполняется: курс с заголовком
 * семейства канвы + членство в группе + урок канвы + факт посещения.
 * На «сыром» коде с багом (см. PR #2516) тест краснеет с фаталом.
 */
class StudentDashboardKanvaBranchTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function dashboard_renders_200_for_student_with_kanva_family_course_and_attendance_fact(): void
    {
        $course = Course::factory()->create([
            'title' => 'Грамматика по Кочергиной — канва',
            'is_active' => true,
        ]);
        $this->assertSame(
            'kochergina',
            TextbookScale::courseFamilyPublic((string) $course->title),
            'Fixture course title must map to a canvas family, or the kanva branch is not exercised'
        );

        $group = Group::factory()->create(['name' => 'Канва fixture']);
        $course->groups()->attach($group->id);

        $student = User::factory()->create();
        $group->users()->attach($student->id);

        $day = now()->subDays(3);
        $schedule = Schedule::create([
            'title' => 'Занятие канвы (fixture)',
            'start' => $day->format('Y-m-d H:i:s'),
            'group_id' => $group->id,
            'course_id' => $course->id,
        ]);
        Lesson::create([
            'title' => 'Кочергина 1 (читка)',
            'course_id' => $course->id,
            'lesson_date' => $day->format('Y-m-d H:i:s'),
        ]);
        WebinarAttendance::create([
            'schedule_id' => $schedule->id,
            'user_id' => $student->id,
            'zoom_participant_uuid' => 'h4641-fixture',
            'name' => 'H4641',
            'joined_at' => $day,
            'duration_seconds' => 1800,
        ]);

        $response = $this->actingAs($student)->get(route('student.dashboard'));

        $response->assertOk();
        $canvas = $response->viewData('canvasByCourseId');
        $this->assertArrayHasKey($course->id, $canvas);
        $this->assertSame('kochergina', $canvas[$course->id]['family']);
        $this->assertSame(1, $canvas[$course->id]['student'], 'Student cursor should sit on kanva lesson 1');
    }

    /** @test */
    public function dashboard_survives_student_without_kanva_courses(): void
    {
        // Обычный студент без курса-учебника: канва-ветка молчит, страница 200.
        $student = User::factory()->create();

        $this->actingAs($student)->get(route('student.dashboard'))->assertOk();
    }
}
