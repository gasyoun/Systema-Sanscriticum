<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Filament\Pages\AttendanceDashboard;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H5087 regression · attendancedashboard-teacher-schoolwide-report-access.
 *
 * Same fixture as the NV-04 proof, assertions flipped to the fixed invariant:
 * a teacher sees ONLY their own courses' attendance/roster (scopeTeacherId
 * pattern from TeacherAnalytics) and the unpaid-money block is admin-only.
 */
class H5087AttendanceDashboardTeacherScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.attendance_dashboard' => true]);
    }

    /** @return array{User, User} teacher1 (outsider with empty course), teacher2 (owner of populated course) */
    private function fixture(): array
    {
        $t1 = Teacher::create(['name' => 'T1', 'email' => 't1@example.test']);
        $t2 = Teacher::create(['name' => 'T2', 'email' => 't2@example.test']);
        $teacher1 = User::factory()->create(['name' => 'Чужой учитель', 'role' => Roles::TEACHER, 'teacher_id' => $t1->id]);
        $teacher2 = User::factory()->create(['name' => 'Хозяин курса', 'role' => Roles::TEACHER, 'teacher_id' => $t2->id]);

        // C1 belongs to teacher1 but has NO students.
        Course::factory()->create(['teacher_id' => $t1->id, 'title' => 'Пустой курс T1']);

        // C2 belongs to teacher2 with a populated roster.
        $course2 = Course::factory()->create(['teacher_id' => $t2->id, 'title' => 'Курс учителя T2']);
        $group2 = Group::create(['name' => 'Группа H5087']);
        $course2->groups()->attach($group2->id);
        $student = User::factory()->create(['name' => 'ИндираH5087']);
        $group2->users()->attach($student->id);
        $schedule = Schedule::create([
            'title' => 'Занятие H5087', 'start' => now()->subDay(), 'group_id' => $group2->id, 'course_id' => $course2->id,
        ]);
        WebinarAttendance::create([
            'schedule_id' => $schedule->id, 'user_id' => $student->id,
            'zoom_participant_uuid' => 'h5087', 'joined_at' => now()->subDay(), 'duration_seconds' => 3600,
        ]);

        return [$teacher1, $teacher2];
    }

    /** @test */
    public function teacher_sees_only_own_courses_no_money_block(): void
    {
        [$teacher1] = $this->fixture();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($teacher1);

        $this->assertTrue(AttendanceDashboard::canAccess(), 'teacher role still admitted');

        Livewire::test(AttendanceDashboard::class)
            ->assertOk()
            // Another teacher's student/course are NOT rendered anymore.
            ->assertDontSee('ИндираH5087', false)
            ->assertDontSee('Курс учителя T2', false)
            // The unpaid-money block is admin-only (H4443 docblock enforced).
            ->assertDontSee('неоплаченные блоки', false);
    }

    /** @test */
    public function control_admin_sees_schoolwide_rows_and_money_block(): void
    {
        $this->fixture();
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(AttendanceDashboard::class)
            ->assertOk()
            ->assertSee('ИндираH5087', false)
            ->assertSee('неоплаченные блоки', false);
    }

    /** @test */
    public function control_student_cannot_access(): void
    {
        $student = User::factory()->create();
        $this->actingAs($student);
        $this->assertFalse(AttendanceDashboard::canAccess());
    }
}
