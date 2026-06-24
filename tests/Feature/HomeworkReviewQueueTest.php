<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\HomeworkSubmissionResource\Pages\ListHomeworkSubmissions;
use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class HomeworkReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function submission(Course $course, Lesson $lesson, string $status = HomeworkSubmission::STATUS_SUBMITTED): HomeworkSubmission
    {
        return HomeworkSubmission::create([
            'user_id' => User::factory()->create()->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => $status,
            'last_activity_at' => now(),
        ]);
    }

    /** @test */
    public function bulk_accept_marks_submitted_works_accepted_and_skips_already_accepted(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();

        $submitted = $this->submission($course, $lesson);
        $alreadyAccepted = $this->submission($course, $lesson, HomeworkSubmission::STATUS_ACCEPTED);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListHomeworkSubmissions::class)
            ->callTableBulkAction('accept', [$submitted, $alreadyAccepted], data: ['body' => 'Отлично'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(HomeworkSubmission::STATUS_ACCEPTED, $submitted->fresh()->status);
        $this->assertSame($admin->id, $submitted->fresh()->reviewed_by);
        // Запись комментария-вердикта создана только для реально обработанной работы.
        $this->assertSame(1, $submitted->fresh()->comments()->count());
        $this->assertSame(0, $alreadyAccepted->fresh()->comments()->count());
    }

    /** @test */
    public function bulk_return_requires_a_comment(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();
        $submitted = $this->submission($course, $lesson);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListHomeworkSubmissions::class)
            ->callTableBulkAction('return', [$submitted], data: ['body' => ''])
            ->assertHasTableBulkActionErrors(['body' => 'required']);

        // Статус не изменился — вердикт не вынесен.
        $this->assertSame(HomeworkSubmission::STATUS_SUBMITTED, $submitted->fresh()->status);
    }

    /** @test */
    public function bulk_return_sends_work_back_with_comment(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher@example.test']);
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();
        $submitted = $this->submission($course, $lesson);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListHomeworkSubmissions::class)
            ->callTableBulkAction('return', [$submitted], data: ['body' => 'Поправьте транслитерацию'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(HomeworkSubmission::STATUS_NEEDS_REVISION, $submitted->fresh()->status);
        $this->assertSame('Поправьте транслитерацию', $submitted->fresh()->comments()->first()->body);
    }
}
