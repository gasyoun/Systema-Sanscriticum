<?php

declare(strict_types=1);

namespace Tests\Feature\Trial;

use App\Models\Course;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H4818 (R2609-01), Verification bullet 2 — "flag-off = нулевое
 * поведение-изменение": golden-diff for the paid-trial booking flow
 * (TrialController::create). Same fixture/assertions shape as
 * TrialPurchaseTest::guest_buys_trial_creates_pending_payment_and_redirects,
 * plus an explicit assertion that the new H4818 code path (Deal tagging +
 * placement_rung) never fires when the flag is off — the default.
 */
class TrialFlagOffGoldenDiffTest extends TestCase
{
    use RefreshDatabase;

    private const ZOOM = 'https://zoom.us/j/123456';

    /** @return array{0: Course, 1: Schedule} */
    private function courseWithTrial(float $price = 500): array
    {
        $course = Course::factory()->create(['slug' => 'grammatika-hindi-sreda']);
        $schedule = Schedule::create([
            'title' => 'Грамматика хинди (среда 7:00)',
            'course_id' => $course->id,
            'group_id' => null,
            'start' => now()->addDays(3)->setTime(7, 0),
            'link' => self::ZOOM,
        ]);
        $course->update(['trial_price' => $price, 'trial_schedule_id' => $schedule->id]);

        return [$course->fresh(), $schedule];
    }

    /** @test */
    public function f2_placement_quiz_flag_is_off_by_default(): void
    {
        $this->assertFalse(config('features.f2_placement_quiz'));
    }

    /** @test */
    public function paid_trial_booking_is_unchanged_when_flag_off_even_with_a_stale_placement_rung_in_session(): void
    {
        config(['features.f2_placement_quiz' => false]);
        [$course] = $this->courseWithTrial(500);

        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/abc', 'paymentLinkId' => 'pl_1'],
        ], 200)]);

        // A leftover session value (e.g. flag was on earlier, then flipped off)
        // must never leak into a Deal write while the flag is off.
        session(['placement_rung' => 'B1']);

        $this->post(route('trial.create', $course->slug), [
            'surname' => 'Иванов', 'name' => 'Иван', 'email' => 'guest@example.test', 'city' => 'Москва',
        ])->assertRedirect('https://pay.tochka/abc');

        $this->assertDatabaseHas('payments', [
            'course_id' => $course->id, 'tariff' => 'trial', 'status' => 'pending', 'amount' => 500,
        ]);
        $this->assertDatabaseHas('users', ['email' => 'guest@example.test', 'name' => 'Иванов Иван, Москва']);

        // The H4818 additive block must not run: no Deal opened, crm_trial_booking
        // untouched by this test, deals table stays empty.
        $this->assertSame(0, Deal::count());
    }

    /** @test */
    public function payment_transaction_shape_is_identical_regardless_of_flag(): void
    {
        // Same Payment row shape TrialController::create() has always produced —
        // the H4818 block sits strictly after this transaction and never touches it.
        [$course] = $this->courseWithTrial(500);
        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/abc', 'paymentLinkId' => 'pl_1'],
        ], 200)]);

        foreach ([false, true] as $i => $flag) {
            // First post logs the guest in (resolveUser's auth()->login) — log
            // out again, or the second iteration silently skips guest creation
            // (same caveat TrialPurchaseTest documents at its own signup_source test).
            auth()->logout();
            config(['features.f2_placement_quiz' => $flag]);
            $email = "guest-{$i}@example.test";

            $this->post(route('trial.create', $course->slug), [
                'surname' => 'Иванов', 'name' => 'Иван', 'email' => $email, 'city' => 'Москва',
            ])->assertRedirect('https://pay.tochka/abc');

            $user = User::where('email', $email)->first();
            $this->assertNotNull($user, "user should exist for {$email}");

            $payment = Payment::where('tariff', 'trial')->where('user_id', $user->id)->first();

            $this->assertNotNull($payment);
            $this->assertSame('pending', $payment->status);
            $this->assertSame($course->id, $payment->course_id);
            $this->assertEquals(500, (float) $payment->amount);
        }
    }
}
