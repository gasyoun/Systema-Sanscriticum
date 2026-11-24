<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * H8245-регрессия (24-11-2026) · HomeworkController::ensureLessonAccessible
 * не знал про B2B-ключи группы.
 *
 * Контракт: ученик, чей урок открыт групповой B2B-оплатой (виртуальный ключ
 * block_N из B2bGroupEntitlement), сдаёт ДЗ в этот урок так же, как ученик со
 * своим платежом. До правки плеер пускал (ключ есть в getUserUnlockedTariffs),
 * а POST /homework отбивался 403 «Нет доступа к этому уроку», потому что гейт
 * ДЗ собирал ключи только из собственных payments.tariff ученика
 * (гр. 157 курса 463 «Грамматика по Кочергиной»: 11 из 13 человек).
 *
 * Клубный ключ при этом ДЗ по-прежнему не открывает — это отдельная политика
 * (ClubEntitlementAccessTest), и правка её не касается.
 */
class B2bGroupHomeworkGateTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    private Lesson $lesson;

    private User $student;

    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        config(['features.b2b_group_payment' => true]);

        $this->course = Course::factory()->create();
        $this->group = Group::factory()->create();
        $this->course->groups()->attach($this->group->id);

        $this->lesson = Lesson::factory()->for($this->course)->create([
            'group_id' => $this->group->id,
            'block_number' => 1,
            'is_free' => false,
            'homework_enabled' => true,
            'homework_prompt' => 'Задание',
        ]);

        $this->student = User::factory()->create();
        $this->student->groups()->attach($this->group->id);
        $this->payer = User::factory()->create();
    }

    /** Групповой платёж юрлица за блок 1 — без событий, чтобы не выдавать доступ платежом. */
    private function groupPayment(string $status = 'pending', bool $accessWhilePending = true): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $this->payer->id,
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'tariff' => Payment::TARIFF_B2B_GROUP,
            'start_block' => 1,
            'end_block' => 1,
            'amount' => 88000,
            'status' => $status,
            'is_conditional' => false,
            'access_while_pending' => $accessWhilePending,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'payment_method' => 'cash',
        ]))->fresh();
    }

    private function submitHomework(): TestResponse
    {
        return $this->actingAs($this->student)->post(
            route('student.homework.store', [$this->course->slug, $this->lesson->id]),
            ['action' => 'submit', 'body' => 'Мой ответ'],
        );
    }

    private function assertSubmissionLanded(): void
    {
        $this->assertDatabaseHas('homework_submissions', [
            'user_id' => $this->student->id,
            'lesson_id' => $this->lesson->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
        ]);
    }

    public function test_pending_group_payment_lets_the_student_submit_homework(): void
    {
        config(['features.b2b_group_pending_access' => true]);
        $this->groupPayment();

        $this->submitHomework()->assertRedirect();

        $this->assertSubmissionLanded();
    }

    public function test_paid_group_payment_lets_the_student_submit_homework(): void
    {
        $this->groupPayment(status: 'paid');

        $this->submitHomework()->assertRedirect();

        $this->assertSubmissionLanded();
    }

    public function test_pending_group_payment_without_the_mark_still_403s(): void
    {
        config(['features.b2b_group_pending_access' => true]);
        $this->groupPayment(accessWhilePending: false);

        $this->submitHomework()->assertForbidden();

        $this->assertDatabaseCount('homework_submissions', 0);
    }

    public function test_flag_off_keeps_the_gate_closed(): void
    {
        config(['features.b2b_group_payment' => false]);
        $this->groupPayment(status: 'paid');

        $this->submitHomework()->assertForbidden();

        $this->assertDatabaseCount('homework_submissions', 0);
    }

    public function test_student_own_payment_still_opens_homework(): void
    {
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'tariff' => 'block_1',
            'start_block' => 1,
            'end_block' => 1,
            'amount' => 8000,
            'status' => 'paid',
            'is_conditional' => false,
        ]));

        $this->submitHomework()->assertRedirect();

        $this->assertSubmissionLanded();
    }
}
