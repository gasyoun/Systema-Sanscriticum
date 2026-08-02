<?php

declare(strict_types=1);

namespace Tests\Feature\Promises;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\PaymentPromiseSuggestion;
use App\Models\User;
use App\Services\ConditionalAccessGranter;
use App\Services\Promises\PaymentPromiseSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentPromiseSuggestionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuggestion(User $user, ?Course $course = null): PaymentPromiseSuggestion
    {
        return PaymentPromiseSuggestion::create([
            'user_id' => $user->id,
            'course_id' => $course?->id,
            'source_type' => PaymentPromiseSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 42,
            'detected_text' => 'Оплачу 20 августа',
            'suggested_date' => '2026-08-20',
            'suggested_amount' => 4500,
            'candidate_course_ids' => $course ? [$course->id] : null,
            'confidence' => 0.9,
            'status' => PaymentPromiseSuggestion::STATUS_PENDING,
        ]);
    }

    public function test_approve_creates_active_payment_promise(): void
    {
        $curator = User::factory()->create();
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $suggestion = $this->makeSuggestion($user, $course);

        $promise = app(PaymentPromiseSuggestionService::class)->approve($suggestion, [
            'promised_at' => '2026-08-20',
            'course_id' => $course->id,
            'amount' => 4500,
            'note' => 'Из TG',
            'grant_access' => false,
        ], $curator);

        $this->assertInstanceOf(PaymentPromise::class, $promise);
        $this->assertSame(PaymentPromise::STATUS_ACTIVE, $promise->status);
        $this->assertSame($user->id, $promise->user_id);
        $this->assertSame($course->id, $promise->course_id);
        $this->assertSame('2026-08-20', $promise->promised_at->toDateString());

        $suggestion->refresh();
        $this->assertSame(PaymentPromiseSuggestion::STATUS_APPROVED, $suggestion->status);
        $this->assertSame($curator->id, $suggestion->resolved_by);
        $this->assertSame($promise->id, $suggestion->payment_promise_id);
        $this->assertNotNull($suggestion->resolved_at);
    }

    public function test_approve_without_grant_does_not_create_conditional_payment(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $suggestion = $this->makeSuggestion($user, $course);

        $promise = app(PaymentPromiseSuggestionService::class)->approve($suggestion, [
            'promised_at' => '2026-08-20',
            'course_id' => $course->id,
            'grant_access' => false,
        ], null);

        $this->assertSame(0, Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('is_conditional', true)
            ->count());
        $this->assertSame(PaymentPromise::STATUS_ACTIVE, $promise->status);
    }

    public function test_unreliable_still_detects_but_grant_access_refused(): void
    {
        $user = User::factory()->create(['is_unreliable' => true]);
        $course = Course::factory()->create();
        $suggestion = $this->makeSuggestion($user, $course);

        // Promise without grant is OK
        $promise = app(PaymentPromiseSuggestionService::class)->approve($suggestion, [
            'promised_at' => '2026-08-22',
            'course_id' => $course->id,
            'grant_access' => false,
        ], null);
        $this->assertSame(PaymentPromise::STATUS_ACTIVE, $promise->status);

        // Fresh pending for grant path
        $suggestion2 = PaymentPromiseSuggestion::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'source_type' => PaymentPromiseSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 99,
            'detected_text' => 'Оплачу через неделю',
            'suggested_date' => '2026-08-29',
            'confidence' => 0.9,
            'status' => PaymentPromiseSuggestion::STATUS_PENDING,
        ]);

        $this->expectException(ValidationException::class);
        app(PaymentPromiseSuggestionService::class)->approve($suggestion2, [
            'promised_at' => '2026-08-29',
            'course_id' => $course->id,
            'grant_access' => true,
            'access_mode' => ConditionalAccessGranter::MODE_FULL,
        ], null);
    }

    public function test_approve_updates_existing_active_promise_same_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $existing = PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => '2026-08-10',
            'status' => PaymentPromise::STATUS_ACTIVE,
            'note' => 'old',
        ]);
        $suggestion = $this->makeSuggestion($user, $course);

        $promise = app(PaymentPromiseSuggestionService::class)->approve($suggestion, [
            'promised_at' => '2026-08-20',
            'course_id' => $course->id,
            'note' => 'updated from chat',
            'grant_access' => false,
        ], null);

        $this->assertSame($existing->id, $promise->id);
        $this->assertSame('2026-08-20', $promise->promised_at->toDateString());
        $this->assertDatabaseCount('payment_promises', 1);
    }

    public function test_dismiss_does_not_create_promise(): void
    {
        $curator = User::factory()->create();
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $suggestion = $this->makeSuggestion($user, $course);

        app(PaymentPromiseSuggestionService::class)->dismiss($suggestion, $curator);

        $suggestion->refresh();
        $this->assertSame(PaymentPromiseSuggestion::STATUS_DISMISSED, $suggestion->status);
        $this->assertNull($suggestion->payment_promise_id);
        $this->assertDatabaseCount('payment_promises', 0);
    }
}
