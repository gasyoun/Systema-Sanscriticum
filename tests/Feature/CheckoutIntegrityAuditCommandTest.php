<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutIntegrityAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_repairs_are_dark_by_default(): void
    {
        $this->assertFalse(config('features.checkout_integrity_safe_repairs'));
    }

    public function test_clean_database_returns_success(): void
    {
        $this->artisan('payments:audit-checkout-integrity')
            ->expectsOutputToContain('Mode: DRY RUN (no writes)')
            ->expectsOutputToContain('All integrity buckets empty — OK.')
            ->assertSuccessful();
    }

    public function test_dry_run_reports_all_categories_and_fails_when_buckets_non_empty(): void
    {
        $fixture = $this->seedIntegrityIssues();

        $this->artisan('payments:audit-checkout-integrity')
            ->expectsOutputToContain('Mode: DRY RUN (no writes)')
            ->expectsOutputToContain('Negative referral wallets: 1')
            ->expectsOutputToContain('Reversed orders with stranded deposit credit: 1')
            ->expectsOutputToContain('Promo counter mismatches: 1')
            ->expectsOutputToContain('Legacy pending promo links without expiry: 1')
            ->expectsOutputToContain('Integrity buckets non-empty (FAILURE)')
            ->assertFailed();

        $this->assertSame(-250.0, (float) $fixture['user']->fresh()->referral_credit);
        $this->assertSame(1000.0, (float) $fixture['deposit']->fresh()->consumed_amount);
        $this->assertSame(7, $fixture['promo']->fresh()->used_count);
        $this->assertSame('pending', $fixture['legacy']->fresh()->status);
    }

    public function test_apply_safe_refuses_to_write_while_feature_flag_is_off(): void
    {
        $fixture = $this->seedIntegrityIssues();

        $this->artisan('payments:audit-checkout-integrity', ['--apply-safe' => true])
            ->expectsOutputToContain('Safe repairs are disabled.')
            ->assertFailed();

        $this->assertSame(7, $fixture['promo']->fresh()->used_count);
    }

    public function test_apply_safe_repairs_only_promo_counter_and_rerun_keeps_manual_findings(): void
    {
        config()->set('features.checkout_integrity_safe_repairs', true);
        $fixture = $this->seedIntegrityIssues();

        $this->artisan('payments:audit-checkout-integrity', ['--apply-safe' => true])
            ->expectsOutputToContain('Mode: APPLY SAFE (promo counters only)')
            ->expectsOutputToContain('Applied promo counters: 1')
            ->assertFailed();

        $this->assertSame(1, $fixture['promo']->fresh()->used_count);
        $this->assertSame(-250.0, (float) $fixture['user']->fresh()->referral_credit);
        $this->assertSame(1000.0, (float) $fixture['deposit']->fresh()->consumed_amount);
        $this->assertSame(1000.0, (float) $fixture['reversed']->fresh()->deposit_credit_applied);
        $this->assertSame('pending', $fixture['legacy']->fresh()->status);

        $this->artisan('payments:audit-checkout-integrity')
            ->expectsOutputToContain('Promo counter mismatches: 0')
            ->expectsOutputToContain('Negative referral wallets: 1')
            ->expectsOutputToContain('Reversed orders with stranded deposit credit: 1')
            ->expectsOutputToContain('Legacy pending promo links without expiry: 1')
            ->assertFailed();
    }

    public function test_dry_run_reports_rejected_webhook_deliveries_and_fails(): void
    {
        // Два отказных решения журнала (H1359) + одно applied, которое НЕ должно
        // попасть в отчёт об отклонённых доставках.
        PaymentWebhookEvent::create([
            'provider' => 'tochka',
            'payment_id' => null,
            'event_hash' => 'hash-mismatch',
            'bank_status' => 'paid',
            'reported_amount' => 9999,
            'decision' => PaymentWebhookEvent::DECISION_REJECTED_AMOUNT_MISMATCH,
            'created_at' => now(),
        ]);
        PaymentWebhookEvent::create([
            'provider' => 'tochka',
            'payment_id' => null,
            'event_hash' => 'hash-resurrection',
            'bank_status' => 'paid',
            'reported_amount' => null,
            'decision' => PaymentWebhookEvent::DECISION_REJECTED_RESURRECTION,
            'created_at' => now(),
        ]);
        PaymentWebhookEvent::create([
            'provider' => 'tochka',
            'payment_id' => null,
            'event_hash' => 'hash-applied',
            'bank_status' => 'paid',
            'reported_amount' => null,
            'decision' => PaymentWebhookEvent::DECISION_APPLIED,
            'created_at' => now(),
        ]);

        $this->artisan('payments:audit-checkout-integrity')
            ->expectsOutputToContain('Rejected webhook deliveries: 2')
            ->assertFailed();
    }

    public function test_paid_but_no_group_bucket_fails_and_names_payment_id(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        // Course has a group, but the paid user is not in it.
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        $payment = Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]));

        $this->artisan('payments:audit-checkout-integrity')
            ->expectsOutputToContain('Paid but no course group membership: 1')
            ->expectsOutputToContain("Paid-but-no-group payment id(s): {$payment->id}")
            ->assertFailed();
    }

    public function test_paid_with_group_membership_is_not_flagged(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        $user->groups()->attach($group->id);

        Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]));

        $this->artisan('payments:audit-checkout-integrity')
            ->expectsOutputToContain('Paid but no course group membership: 0')
            ->expectsOutputToContain('All integrity buckets empty — OK.')
            ->assertSuccessful();
    }

    public function test_deposit_tariff_is_excluded_from_paid_but_no_group(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
            'is_conditional' => false,
        ]));

        $this->artisan('payments:audit-checkout-integrity')
            ->expectsOutputToContain('Paid but no course group membership: 0')
            ->assertSuccessful();
    }

    public function test_non_revenue_tariffs_are_excluded_from_paid_but_no_group(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        foreach (['Расход', 'salary_payout'] as $tariff) {
            Payment::withoutEvents(fn (): Payment => Payment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'amount' => -1000,
                'tariff' => $tariff,
                'status' => 'paid',
                'is_conditional' => false,
            ]));
        }

        $this->artisan('payments:audit-checkout-integrity')
            ->expectsOutputToContain('Paid but no course group membership: 0')
            ->expectsOutputToContain('Paid on group-less course (informational, not failing): 0')
            ->assertSuccessful();
    }

    public function test_groupless_course_paid_is_informational_not_failing(): void
    {
        $user = User::factory()->create();
        // Course with no groups attached — historical residual, not an actionable hole.
        $course = Course::factory()->create();

        Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]));

        $this->artisan('payments:audit-checkout-integrity')
            ->expectsOutputToContain('Paid but no course group membership: 0')
            ->expectsOutputToContain('Paid on group-less course (informational, not failing): 1')
            ->expectsOutputToContain('All integrity buckets empty — OK.')
            ->assertSuccessful();
    }

    private function seedIntegrityIssues(): array
    {
        $user = User::factory()->create();
        $user->updateQuietly(['referral_credit' => -250]);
        $course = Course::factory()->create();
        $promo = PromoCode::create([
            'code' => 'AUDITME',
            'type' => 'percent',
            'value' => 10,
            'used_count' => 7,
            'is_active' => true,
        ]);

        $deposit = Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
            'consumed_amount' => 1000,
            'deposit_consumed_at' => now(),
        ]));

        $reversed = Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4000,
            'tariff' => 'full',
            'status' => 'failed',
            'deposit_credit_applied' => 1000,
        ]));

        Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promo_code_id' => $promo->id,
            'amount' => 4500,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]));

        Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promo_code_id' => $promo->id,
            'amount' => 0,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => true,
        ]));

        $legacy = Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promo_code_id' => $promo->id,
            'amount' => 4500,
            'tariff' => 'full',
            'status' => 'pending',
            'payment_link_expires_at' => null,
        ]));

        // Paid full payment with no group membership (bucket under test elsewhere;
        // seed also contributes to multi-bucket FAILURE here).
        return compact('user', 'deposit', 'reversed', 'promo', 'legacy');
    }
}
