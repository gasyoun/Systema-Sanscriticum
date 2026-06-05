<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\StudentGroupResource;
use App\Filament\Resources\StudentGroupResource\Pages\ListStudentGroups;
use App\Filament\Resources\StudentGroupResource\Pages\ViewStudentGroup;
use App\Filament\Resources\StudentGroupResource\RelationManagers\StudentsRelationManager;
use App\Models\Course;
use App\Models\Group;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentGroupResourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeacher(string $email): array
    {
        $teacher = Teacher::create(['name' => 'Препод '.$email, 'email' => $email]);
        $teacherUser = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);

        return [$teacher, $teacherUser];
    }

    /** Группа с курсом преподавателя и набором учеников. */
    private function makeGroupForTeacher(Teacher $teacher, int $students = 0): Group
    {
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $group = Group::create(['name' => 'Группа '.uniqid()]);
        $group->courses()->attach($course->id);

        for ($i = 0; $i < $students; $i++) {
            $group->users()->attach(User::factory()->create()->id);
        }

        return $group;
    }

    /** @test */
    public function teacher_sees_only_groups_of_their_courses(): void
    {
        [$teacherA, $teacherAUser] = $this->makeTeacher('a@example.test');
        [$teacherB] = $this->makeTeacher('b@example.test');

        $groupA = $this->makeGroupForTeacher($teacherA, students: 2);
        $groupB = $this->makeGroupForTeacher($teacherB, students: 3);

        $this->actingAs($teacherAUser);
        $ids = StudentGroupResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($ids->contains($groupA->id));
        $this->assertFalse($ids->contains($groupB->id), 'Препод A не должен видеть группу курса препода B.');
    }

    /** @test */
    public function teacher_cannot_view_foreign_group(): void
    {
        [$teacherA, $teacherAUser] = $this->makeTeacher('a@example.test');
        [$teacherB] = $this->makeTeacher('b@example.test');

        $own = $this->makeGroupForTeacher($teacherA);
        $foreign = $this->makeGroupForTeacher($teacherB);

        $this->actingAs($teacherAUser);

        $this->assertTrue(StudentGroupResource::canView($own));
        $this->assertFalse(StudentGroupResource::canView($foreign));
    }

    /** @test */
    public function teacher_without_teacher_id_sees_nothing(): void
    {
        [$teacher] = $this->makeTeacher('a@example.test');
        $this->makeGroupForTeacher($teacher, students: 1);

        $orphan = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => null]);
        $this->actingAs($orphan);

        $this->assertSame(0, StudentGroupResource::getEloquentQuery()->count());
    }

    /** @test */
    public function resource_is_read_only_for_teachers(): void
    {
        [$teacher, $teacherUser] = $this->makeTeacher('a@example.test');
        $this->makeGroupForTeacher($teacher);

        $this->actingAs($teacherUser);

        $this->assertFalse(StudentGroupResource::canCreate());
        $this->assertFalse(StudentGroupResource::canEdit($teacher));
        $this->assertFalse(StudentGroupResource::canDelete($teacher));
    }

    /** @test */
    public function pages_render_for_teacher(): void
    {
        [$teacher, $teacherUser] = $this->makeTeacher('a@example.test');
        $group = $this->makeGroupForTeacher($teacher, students: 2);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($teacherUser);

        Livewire::test(ListStudentGroups::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$group]);

        Livewire::test(ViewStudentGroup::class, ['record' => $group->getRouteKey()])
            ->assertOk();

        Livewire::test(StudentsRelationManager::class, [
            'ownerRecord' => $group,
            'pageClass' => ViewStudentGroup::class,
        ])->assertOk()->assertCanSeeTableRecords($group->users);
    }
}
