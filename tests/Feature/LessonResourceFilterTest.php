<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\LessonResource\Pages\ListLessons;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LessonResourceFilterTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function course_filter_matches_by_id_not_title_for_same_named_courses(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
        $teacherUser = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);

        // Два РАЗНЫХ курса с одинаковым названием (когорты по годам) — как в проде.
        $courseA = Course::factory()->create(['title' => 'Грамматика хинди ГР.2', 'teacher_id' => $teacher->id]);
        $courseB = Course::factory()->create(['title' => 'Грамматика хинди ГР.2', 'teacher_id' => $teacher->id]);

        $lessonsA = Lesson::factory()->count(3)->for($courseA)->create();
        $lessonsB = Lesson::factory()->count(2)->for($courseB)->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($teacherUser);

        Livewire::test(ListLessons::class)
            ->filterTable('course_id', $courseA->id)
            ->assertCanSeeTableRecords($lessonsA)
            ->assertCanNotSeeTableRecords($lessonsB);
    }

    /** @test */
    public function teacher_sees_only_own_courses_lessons(): void
    {
        $teacherA = Teacher::create(['name' => 'A', 'email' => 'a@example.test']);
        $teacherAUser = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacherA->id]);
        $teacherB = Teacher::create(['name' => 'B', 'email' => 'b@example.test']);

        $ownCourse = Course::factory()->create(['teacher_id' => $teacherA->id]);
        $foreignCourse = Course::factory()->create(['teacher_id' => $teacherB->id]);

        $own = Lesson::factory()->count(2)->for($ownCourse)->create();
        $foreign = Lesson::factory()->count(2)->for($foreignCourse)->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($teacherAUser);

        Livewire::test(ListLessons::class)
            ->assertCanSeeTableRecords($own)
            ->assertCanNotSeeTableRecords($foreign);
    }
}
