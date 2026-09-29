<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H5087 regression · HomeworkController.ensureLessonAccessible.missing-group-visibility.
 *
 * Same two-stream fixture as the NV-02 proof, assertions flipped to the fixed
 * invariant: the homework gate enforces the player-side isVisibleToGroupsOf
 * rule — a cross-stream submission 403s and never lands in the foreign
 * cohort's review queue; the own-stream flow is untouched.
 */
class H5087HomeworkGroupVisibilityFenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    /** @return array{Course, Lesson, Lesson, User} course, lessonA (group A), lessonB (group B), student in B with paid block_1 */
    private function fixture(): array
    {
        $course = Course::factory()->create();
        $groupA = Group::create(['name' => 'Поток A']);
        $groupB = Group::create(['name' => 'Поток B']);
        $course->groups()->attach([$groupA->id, $groupB->id]);

        $lessonA = Lesson::factory()->for($course)->create([
            'title' => 'Урок потока A',
            'group_id' => $groupA->id,
            'is_free' => false,
            'block_number' => 1,
            'homework_enabled' => true,
            'homework_prompt' => 'Задание',
        ]);
        $lessonB = Lesson::factory()->for($course)->create([
            'title' => 'Урок потока B',
            'group_id' => $groupB->id,
            'is_free' => false,
            'block_number' => 1,
            'homework_enabled' => true,
            'homework_prompt' => 'Задание',
        ]);

        $student = User::factory()->create();
        $student->groups()->attach($groupB->id);

        Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'start_block' => 1,
            'end_block' => 1,
            'status' => 'paid',
        ]);

        // Curated two-stream state: fireOnPaid attaches all course groups,
        // a manager keeps the student in their OWN stream only.
        $student->groups()->detach($groupA->id);

        return [$course, $lessonA, $lessonB, $student];
    }

    /** @test */
    public function cross_group_homework_submission_is_rejected(): void
    {
        [$course, $lessonA, , $student] = $this->fixture();

        // Sanity: the player-side isolation rule marks this lesson invisible.
        $this->assertFalse($lessonA->isVisibleToGroupsOf($student->fresh()));

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lessonA->id]),
            ['action' => 'submit', 'body' => 'Кросс-поточная сдача'],
        )->assertForbidden();

        $this->assertNull(
            HomeworkSubmission::where('user_id', $student->id)->where('lesson_id', $lessonA->id)->first(),
            'no submission row in the foreign stream'
        );
    }

    /** @test */
    public function control_own_group_lesson_still_submits(): void
    {
        [$course, , $lessonB, $student] = $this->fixture();

        $this->actingAs($student)->post(
            route('student.homework.store', [$course->slug, $lessonB->id]),
            ['action' => 'submit', 'body' => 'Своя сдача'],
        )->assertRedirect();

        $this->assertNotNull(
            HomeworkSubmission::where('user_id', $student->id)->where('lesson_id', $lessonB->id)->first()
        );
    }

    /** @test */
    public function control_unpaid_student_is_still_blocked_by_tariff_gate(): void
    {
        [$course, $lessonA] = $this->fixture();
        $poor = User::factory()->create();

        $this->actingAs($poor)->post(
            route('student.homework.store', [$course->slug, $lessonA->id]),
            ['action' => 'submit', 'body' => 'x'],
        )->assertForbidden();
    }
}
