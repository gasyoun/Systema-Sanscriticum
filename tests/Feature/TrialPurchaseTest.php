<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrialPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    /** Курс с настроенным пробным занятием. */
    private function courseWithTrial(float $price = 500): array
    {
        $course = Course::factory()->create(['slug' => 'grammatika-hindi-sreda', 'trial_price' => $price]);
        $lesson = Lesson::factory()->for($course)->create(['is_free' => false, 'block_number' => 1]);
        $course->update(['trial_lesson_id' => $lesson->id]);

        return [$course->fresh(), $lesson];
    }

    /** @test */
    public function guest_buys_trial_creates_pending_payment_and_redirects(): void
    {
        [$course, $lesson] = $this->courseWithTrial(500);

        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/abc', 'paymentLinkId' => 'pl_1'],
        ], 200)]);

        $this->post(route('trial.create', $course->slug), [
            'name' => 'Иван',
            'email' => 'guest@example.test',
        ])->assertRedirect('https://pay.tochka/abc');

        $this->assertDatabaseHas('payments', [
            'course_id' => $course->id,
            'tariff' => 'trial',
            'status' => 'pending',
            'amount' => 500,
        ]);
        $this->assertDatabaseHas('users', ['email' => 'guest@example.test']);
    }

    /** @test */
    public function trial_is_unavailable_without_price_or_lesson(): void
    {
        $course = Course::factory()->create(['slug' => 'no-trial', 'trial_price' => null, 'trial_lesson_id' => null]);

        $this->post(route('trial.create', $course->slug), [
            'name' => 'Иван', 'email' => 'g@example.test',
        ])->assertForbidden();
    }

    /** @test */
    public function paid_trial_grants_lesson_access_idempotently(): void
    {
        [$course, $lesson] = $this->courseWithTrial();
        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 500, 'tariff' => 'trial', 'status' => 'pending',
        ]);

        // Вебхук перевёл в paid → Payment::processTrial выдал grant.
        $payment->update(['status' => 'paid']);

        $this->assertEquals(1, LessonAccessGrant::where('user_id', $user->id)->where('lesson_id', $lesson->id)->active()->count());

        // Повторная оплата (второй платёж) не плодит второй активный grant.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 500, 'tariff' => 'trial', 'status' => 'paid',
        ]);

        $this->assertEquals(1, LessonAccessGrant::where('user_id', $user->id)->where('lesson_id', $lesson->id)->active()->count());
    }

    /** @test */
    public function trial_grant_opens_only_that_lesson_for_buyer(): void
    {
        [$course, $trialLesson] = $this->courseWithTrial();
        // Платный урок того же курса, который НЕ куплен.
        $otherLesson = Lesson::factory()->for($course)->create(['is_free' => false, 'block_number' => 1]);

        $buyer = User::factory()->create();
        Payment::create([
            'user_id' => $buyer->id, 'course_id' => $course->id,
            'amount' => 500, 'tariff' => 'trial', 'status' => 'paid',
        ]);

        // Покупатель видит пробный урок...
        $this->actingAs($buyer)
            ->get(route('student.lesson', [$course->slug, $trialLesson->id]))
            ->assertOk();

        // ...но не другой платный урок курса.
        $this->actingAs($buyer)
            ->get(route('student.lesson', [$course->slug, $otherLesson->id]))
            ->assertRedirect(route('student.course', $course->slug));

        // Посторонний не видит пробный урок.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->get(route('student.lesson', [$course->slug, $trialLesson->id]))
            ->assertRedirect(route('student.course', $course->slug));
    }

    /** @test */
    public function trial_grant_overrides_group_visibility(): void
    {
        [$course, $trialLesson] = $this->courseWithTrial();
        // Пробный урок привязан к группе, в которой покупателя нет.
        $group = Group::create(['name' => 'Поток A']);
        $trialLesson->update(['group_id' => $group->id]);

        $buyer = User::factory()->create();
        Payment::create([
            'user_id' => $buyer->id, 'course_id' => $course->id,
            'amount' => 500, 'tariff' => 'trial', 'status' => 'paid',
        ]);

        // Персональный grant главнее группового гейта.
        $this->actingAs($buyer)
            ->get(route('student.lesson', [$course->slug, $trialLesson->id]))
            ->assertOk();
    }

    /** @test */
    public function trial_amount_credits_and_is_consumed_by_real_purchase(): void
    {
        [$course, $lesson] = $this->courseWithTrial(500);
        $user = User::factory()->create();

        $trial = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 500, 'tariff' => 'trial', 'status' => 'paid',
        ]);

        // Незачтённый кредит включает пробное.
        $credit = Payment::query()
            ->where('user_id', $user->id)->where('course_id', $course->id)
            ->unconsumedDeposits()->sum('amount');
        $this->assertEquals(500, (float) $credit);

        // Реальная покупка гасит кредит пробного.
        $full = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 9000, 'tariff' => 'full', 'status' => 'pending',
        ]);
        $full->consumeDepositsForCourse();

        $this->assertNotNull($trial->fresh()->deposit_consumed_at);
        $this->assertEquals(0, (float) Payment::query()
            ->where('user_id', $user->id)->where('course_id', $course->id)
            ->unconsumedDeposits()->sum('amount'));
    }

    /** @test */
    public function guest_with_existing_email_is_rejected(): void
    {
        [$course] = $this->courseWithTrial();
        User::factory()->create(['email' => 'taken@example.test']);

        $this->post(route('trial.create', $course->slug), [
            'name' => 'Иван', 'email' => 'taken@example.test',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('payments', ['course_id' => $course->id, 'tariff' => 'trial']);
    }
}
