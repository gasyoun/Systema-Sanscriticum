<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\StuckStudentsWidget;
use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use App\Services\StuckStudentsReport;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StuckStudentsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function inactive_student_is_flagged_with_reason(): void
    {
        $stuck = User::factory()->create([
            'last_login_at' => now()->subDays(40),
            'last_activity_at' => now()->subDays(20),
        ]);

        $ids = StuckStudentsReport::query()->pluck('id');
        $this->assertTrue($ids->contains($stuck->id));

        $reasons = StuckStudentsReport::reasonsFor($stuck->fresh());
        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('Неактивен', implode(' ', $reasons));
    }

    /** @test */
    public function recently_active_student_is_not_flagged(): void
    {
        $active = User::factory()->create([
            'last_login_at' => now()->subDays(40),
            'last_activity_at' => now()->subHours(2),
        ]);

        $this->assertFalse(StuckStudentsReport::query()->pluck('id')->contains($active->id));
    }

    /** @test */
    public function student_with_stalled_rework_is_flagged(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create();
        // Активен недавно, но ДЗ зависло на доработке давно.
        $student = User::factory()->create([
            'last_login_at' => now()->subDays(30),
            'last_activity_at' => now()->subHours(1),
        ]);

        HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_NEEDS_REVISION,
            'reviewed_at' => now()->subDays(10),
            'last_activity_at' => now()->subDays(10),
        ]);

        $this->assertTrue(StuckStudentsReport::query()->pluck('id')->contains($student->id));
        $reasons = StuckStudentsReport::reasonsFor($student->fresh());
        $this->assertStringContainsString('доработке', implode(' ', $reasons));
    }

    /** @test */
    public function admins_are_never_flagged(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'role' => Roles::ADMIN,
            'last_login_at' => now()->subDays(40),
            'last_activity_at' => now()->subDays(40),
        ]);

        $this->assertFalse(StuckStudentsReport::query()->pluck('id')->contains($admin->id));
    }

    /** @test */
    public function widget_lists_stuck_students_for_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => Roles::ADMIN]);
        $stuck = User::factory()->create([
            'last_login_at' => now()->subDays(40),
            'last_activity_at' => now()->subDays(20),
        ]);
        $active = User::factory()->create([
            'last_login_at' => now()->subDays(40),
            'last_activity_at' => now()->subMinutes(5),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(StuckStudentsWidget::class)
            ->assertCanSeeTableRecords([$stuck])
            ->assertCanNotSeeTableRecords([$active]);
    }
}
