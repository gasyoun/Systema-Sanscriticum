<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Course;
use App\Models\Group;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\ConditionalAccessGranter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Promise-грант обязан писать course_user («Записался»), а не только группы:
 * conditional-платежи создаются через withoutEvents, fireOnPaid не стреляет, и
 * единственным источником enrollInCourse был processSuccessfulPayment, мимо
 * которого этот путь проходит. Инцидент 14577/14066 («оплачен, но course_user
 * пуст»).
 */
class ConditionalGrantCourseUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();
    }

    private function promiseFor(User $user, Course $course): PaymentPromise
    {
        return PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 4800,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);
    }

    /** @test */
    public function blocks_mode_promise_grant_enrolls_student_into_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'G']);
        $course->groups()->attach($group->id);

        app(ConditionalAccessGranter::class)
            ->grantForPromise($this->promiseFor($user, $course), ConditionalAccessGranter::MODE_BLOCKS, [1]);

        $this->assertDatabaseHas('course_user', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'Записался',
        ]);
        $this->assertTrue(
            $user->fresh()->groups->pluck('id')->contains($group->id),
            'Группа курса должна быть выдана grantAccess().'
        );
    }

    /** @test */
    public function full_mode_promise_grant_enrolls_student_into_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'G']);
        $course->groups()->attach($group->id);

        app(ConditionalAccessGranter::class)
            ->grantForPromise($this->promiseFor($user, $course), ConditionalAccessGranter::MODE_FULL);

        $this->assertDatabaseHas('course_user', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    /** @test */
    public function enroll_does_not_duplicate_existing_course_user_row(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'G']);
        $course->groups()->attach($group->id);

        // Ручная запись из карточки студента («Записать на курс») раньше гранта.
        $user->courses()->attach($course->id, ['status' => 'Рассрочка']);

        app(ConditionalAccessGranter::class)
            ->grantForPromise($this->promiseFor($user, $course), ConditionalAccessGranter::MODE_BLOCKS, [1]);

        // enrollInCourse не перетирает ручной статус и не создаёт дубль.
        $this->assertDatabaseHas('course_user', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'Рассрочка',
        ]);
        $this->assertDatabaseCount('course_user', 1);
    }
}
