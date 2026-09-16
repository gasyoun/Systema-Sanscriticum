<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BillingCommitmentKind;
use App\Enums\BillingCommitmentStatus;
use App\Enums\BillingSubscriptionMode;
use App\Enums\BillingSubscriptionStatus;
use App\Models\BillingCommitment;
use App\Models\BillingSubscription;
use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Services\Payments\AcceptingPaypalWebhookSignatureVerifier;
use App\Services\Payments\PaypalWebhookSignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * H2027 Phase 1 — PayPal Subscriptions webhook: ledger + Payment materialisation.
 * Signature verifier bound to accepting double; flag still default OFF.
 */
class PaypalSubscriptionsWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(
            PaypalWebhookSignatureVerifier::class,
            AcceptingPaypalWebhookSignatureVerifier::class,
        );
    }

    private function enableConfigured(): void
    {
        config([
            'features.paypal_subscriptions' => true,
            'services.paypal.subscriptions.enabled' => true,
            'services.paypal.subscriptions.client_id' => 'test-client',
            'services.paypal.subscriptions.client_secret' => 'test-secret',
            'services.paypal.subscriptions.webhook_id' => 'WH-test',
            'services.paypal.subscriptions.mode' => 'sandbox',
            'services.paypal.subscriptions.skip_signature_verify' => true,
        ]);
    }

    private function makePaypalSub(User $user, Course $course, string $providerSubId = 'I-SUBTEST1'): BillingSubscription
    {
        if (! $course->groups()->exists()) {
            $course->groups()->attach(Group::factory()->create());
        }

        return BillingSubscription::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'provider' => 'paypal',
            'provider_subscription_id' => $providerSubId,
            'status' => BillingSubscriptionStatus::PendingFirstPay,
            'mode' => BillingSubscriptionMode::PerCourse,
            'amount_rub' => 4900,
        ]);
    }

    private function makeCommitment(
        BillingSubscription $subscription,
        string $accessKey = 'full',
        ?int $startBlock = null,
        ?int $endBlock = null,
    ): BillingCommitment {
        return BillingCommitment::factory()->create([
            'billing_subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'course_id' => $subscription->course_id,
            'kind' => $accessKey === 'full'
                ? BillingCommitmentKind::CourseMonth
                : BillingCommitmentKind::CourseBlock,
            'amount_rub' => 4900,
            'access_key' => $accessKey,
            'start_block' => $startBlock,
            'end_block' => $endBlock,
            'status' => BillingCommitmentStatus::Scheduled,
            'due_on' => today(),
            // H2304 spec 3: ожидаемое списание в валюте провайдера — без него
            // денежное событие отклоняется (fail closed).
            'meta' => [
                'expected_charge_amount' => 49.00,
                'expected_charge_currency' => 'USD',
            ],
        ]);
    }

    /** @test */
    public function activated_event_marks_subscription_active_and_ledgers(): void
    {
        $this->enableConfigured();
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $sub = $this->makePaypalSub($user, $course);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-ACT-1',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource' => [
                'id' => $sub->provider_subscription_id,
                'status' => 'ACTIVE',
            ],
        ])->assertOk();

        $this->assertSame(BillingSubscriptionStatus::Active, $sub->fresh()->status);
        $this->assertSame(1, PaymentWebhookEvent::where('provider', 'paypal')->count());
        $this->assertSame(
            PaymentWebhookEvent::DECISION_APPLIED,
            PaymentWebhookEvent::where('provider', 'paypal')->value('decision')
        );
    }

    /** @test */
    public function sale_completed_creates_paid_paypal_subscription_payment(): void
    {
        $this->enableConfigured();
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $sub = $this->makePaypalSub($user, $course);
        $commitment = $this->makeCommitment($sub);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-SALE-1',
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => [
                'id' => 'SALE-ABC',
                'billing_agreement_id' => $sub->provider_subscription_id,
                'amount' => ['total' => '49.00', 'currency' => 'USD'],
            ],
        ])->assertOk();

        $payment = Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->first();
        $this->assertNotNull($payment);
        $this->assertSame('paid', $payment->status);
        $this->assertSame($user->id, $payment->user_id);
        $this->assertSame($course->id, $payment->course_id);
        $this->assertSame('SALE-ABC', $payment->transaction_id);
        $this->assertEquals(4900.0, (float) $payment->amount);
        $this->assertEquals(49.0, (float) $payment->foreign_amount);
        $this->assertSame('USD', $payment->foreign_currency);
        $this->assertSame('full', $payment->tariff);
        $this->assertTrue($user->fresh()->groups()->whereKey($course->groups()->value('groups.id'))->exists());
        $this->assertSame(BillingCommitmentStatus::Charged, $commitment->fresh()->status);
        $this->assertSame($payment->id, $commitment->fresh()->payment_id);
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->fresh()->status);
    }

    /** @test */
    public function duplicate_event_id_is_idempotent_no_second_payment(): void
    {
        $this->enableConfigured();
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $sub = $this->makePaypalSub($user, $course);
        $commitment = $this->makeCommitment($sub, 'block_2', 2, 3);

        $body = [
            'id' => 'WH-EVT-DUP-1',
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => [
                'id' => 'SALE-DUP',
                'billing_agreement_id' => $sub->provider_subscription_id,
                'amount' => ['total' => '49.00', 'currency' => 'USD'],
            ],
        ];

        $this->postJson('/api/webhooks/paypal-subscriptions', $body)->assertOk();
        $this->postJson('/api/webhooks/paypal-subscriptions', $body)->assertOk();

        $this->assertSame(1, Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->count());
        $this->assertSame(1, PaymentWebhookEvent::where('provider', 'paypal')->count());
        $payment = Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->firstOrFail();
        $this->assertSame('block_2', $payment->tariff);
        $this->assertSame(2, $payment->start_block);
        $this->assertSame(3, $payment->end_block);
        $this->assertSame($payment->id, $commitment->fresh()->payment_id);
    }

    /** @test */
    public function unmatched_charge_fails_for_retry_without_ledgering_success(): void
    {
        $this->enableConfigured();
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $sub = $this->makePaypalSub($user, $course);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-NO-COMMITMENT',
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => [
                'id' => 'SALE-NO-COMMITMENT',
                'billing_agreement_id' => $sub->provider_subscription_id,
                'amount' => ['total' => '49.00', 'currency' => 'USD'],
            ],
        ])->assertStatus(422);

        // H2304 spec 3: отказ теперь журналируется ВНЕ откатываемой транзакции —
        // строка-отказ остаётся, платёж не создаётся, ответ non-2xx (ретрай).
        $this->assertSame(0, Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->count());
        $this->assertSame(1, PaymentWebhookEvent::where('provider', 'paypal')->count());
        $this->assertSame(
            PaymentWebhookEvent::DECISION_REJECTED_CHARGE,
            PaymentWebhookEvent::where('provider', 'paypal')->value('decision')
        );
    }

    /** @test */
    public function ambiguous_due_commitments_fail_for_retry(): void
    {
        $this->enableConfigured();
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $sub = $this->makePaypalSub($user, $course);
        $this->makeCommitment($sub);
        $this->makeCommitment($sub, 'block_1', 1, 1);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-AMBIGUOUS',
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => [
                'id' => 'SALE-AMBIGUOUS',
                'billing_agreement_id' => $sub->provider_subscription_id,
                'amount' => ['total' => '49.00', 'currency' => 'USD'],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->count());
        $this->assertSame(2, BillingCommitment::where('status', BillingCommitmentStatus::Scheduled)->count());
        $this->assertSame(1, PaymentWebhookEvent::where('provider', 'paypal')->count());
    }

    /** @test */
    public function amount_mismatch_rejects_ledgers_and_allows_retry_after_repair(): void
    {
        // H2304 spec 3 acceptance: $1.00 против обязательства, ожидающего $400
        // (аналог ₽40 000) — доступ не выдаётся, строка-отказ в журнале, non-2xx.
        $this->enableConfigured();
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $sub = $this->makePaypalSub($user, $course);
        $commitment = $this->makeCommitment($sub);
        $commitment->update(['meta' => [
            'expected_charge_amount' => 400.00,
            'expected_charge_currency' => 'USD',
        ]]);

        $body = [
            'id' => 'WH-EVT-CHEAP-1',
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => [
                'id' => 'SALE-CHEAP',
                'billing_agreement_id' => $sub->provider_subscription_id,
                'amount' => ['total' => '1.00', 'currency' => 'USD'],
            ],
        ];

        $this->postJson('/api/webhooks/paypal-subscriptions', $body)->assertStatus(422);

        $this->assertSame(0, Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->count());
        $this->assertFalse($user->fresh()->groups()->exists());
        $rejected = PaymentWebhookEvent::where('provider', 'paypal')->firstOrFail();
        $this->assertSame(PaymentWebhookEvent::DECISION_REJECTED_AMOUNT_MISMATCH, $rejected->decision);
        $this->assertEquals(1.00, (float) $rejected->reported_amount);

        // Отказ не занимает canonical hash(eventId): после починки суммы тот же
        // event id проходит заново (PayPal-ретрай), а не упирается в replay-guard.
        $body['resource']['amount']['total'] = '400.00';
        $this->postJson('/api/webhooks/paypal-subscriptions', $body)->assertOk();
        $this->assertSame(1, Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->count());
        $this->assertSame(BillingCommitmentStatus::Charged, $commitment->fresh()->status);
    }

    /** @test */
    public function currency_mismatch_rejects(): void
    {
        $this->enableConfigured();
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $sub = $this->makePaypalSub($user, $course);
        $this->makeCommitment($sub);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-CURR-1',
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => [
                'id' => 'SALE-CURR',
                'billing_agreement_id' => $sub->provider_subscription_id,
                'amount' => ['total' => '49.00', 'currency' => 'EUR'],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->count());
        $this->assertSame(
            PaymentWebhookEvent::DECISION_REJECTED_AMOUNT_MISMATCH,
            PaymentWebhookEvent::where('provider', 'paypal')->value('decision')
        );
    }

    /** @test */
    public function missing_expected_charge_meta_fails_closed(): void
    {
        $this->enableConfigured();
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $sub = $this->makePaypalSub($user, $course);
        $commitment = $this->makeCommitment($sub);
        $commitment->update(['meta' => []]);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-NOEXP-1',
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => [
                'id' => 'SALE-NOEXP',
                'billing_agreement_id' => $sub->provider_subscription_id,
                'amount' => ['total' => '49.00', 'currency' => 'USD'],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->count());
        $this->assertSame(
            PaymentWebhookEvent::DECISION_REJECTED_AMOUNT_MISMATCH,
            PaymentWebhookEvent::where('provider', 'paypal')->value('decision')
        );
    }

    /** @test */
    public function groupless_course_charge_fails_closed_via_central_grant_guard(): void
    {
        // H2304 spec 2 на PayPal-маршруте: grantAccess() бросает, транзакция
        // откатывается — ни платежа, ни доступа, non-2xx (PayPal ретраит).
        $this->enableConfigured();
        config(['features.grant_access_fail_closed' => true]);
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $sub = BillingSubscription::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'provider' => 'paypal',
            'provider_subscription_id' => 'I-NOGROUP',
            'status' => BillingSubscriptionStatus::PendingFirstPay,
            'mode' => BillingSubscriptionMode::PerCourse,
            'amount_rub' => 4900,
        ]);
        $this->makeCommitment($sub);
        $this->assertFalse($course->groups()->exists());

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-NOGROUP-1',
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => [
                'id' => 'SALE-NOGROUP',
                'billing_agreement_id' => 'I-NOGROUP',
                'amount' => ['total' => '49.00', 'currency' => 'USD'],
            ],
        ])->assertStatus(500);

        $this->assertSame(0, Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->count());
        $this->assertFalse($user->fresh()->groups()->exists());
    }

    /** @test */
    public function invalid_signature_returns_401(): void
    {
        $this->enableConfigured();
        $this->app->bind(PaypalWebhookSignatureVerifier::class, function () {
            return new class implements PaypalWebhookSignatureVerifier
            {
                public function verify(Request $request): bool
                {
                    return false;
                }
            };
        });

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-BAD',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource' => ['id' => 'I-X'],
        ])->assertStatus(401);
    }

    /** @test */
    public function phase0_defaults_still_404_when_disabled(): void
    {
        config([
            'features.paypal_subscriptions' => false,
            'services.paypal.subscriptions.enabled' => false,
        ]);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
        ])->assertNotFound();
    }

    private function paidSale(User $user, Course $course, string $saleId = 'SALE-REV-1'): array
    {
        $sub = $this->makePaypalSub($user, $course);
        $commitment = $this->makeCommitment($sub);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-SALE-'.$saleId,
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => [
                'id' => $saleId,
                'billing_agreement_id' => $sub->provider_subscription_id,
                'amount' => ['total' => '49.00', 'currency' => 'USD'],
            ],
        ])->assertOk();

        $payment = Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->firstOrFail();

        return [$sub->fresh(), $commitment->fresh(), $payment];
    }

    /** @test */
    public function h5007_sale_refunded_cancels_payment_and_revokes_access_when_wave1_on(): void
    {
        // Audit H3 (16-09-2026): REFUNDED/REVERSED/DENIED used to fall into the
        // unmatched branch — payment stayed paid, group access stayed.
        $this->enableConfigured();
        config(['features.payment_fix_wave1' => true]);
        $user = User::factory()->create();
        $course = Course::factory()->create();
        [$sub, $commitment, $payment] = $this->paidSale($user, $course);
        $groupId = $course->groups()->value('groups.id');
        $this->assertTrue($user->fresh()->groups()->whereKey($groupId)->exists());

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-REFUND-1',
            'event_type' => 'PAYMENT.SALE.REFUNDED',
            'resource' => [
                'id' => 'REFUND-1',
                'sale_id' => 'SALE-REV-1',
                'amount' => ['total' => '-49.00', 'currency' => 'USD'],
            ],
        ])->assertOk();

        $this->assertSame('canceled', $payment->fresh()->status);
        $this->assertSame(BillingCommitmentStatus::Cancelled, $commitment->fresh()->status);
        $this->assertFalse($user->fresh()->groups()->whereKey($groupId)->exists(), 'refund must revoke group access');
        $this->assertSame(
            PaymentWebhookEvent::DECISION_APPLIED,
            PaymentWebhookEvent::where('bank_status', 'PAYMENT.SALE.REFUNDED')->value('decision')
        );
    }

    /** @test */
    public function h5007_sale_denied_marks_commitment_failed_and_subscription_past_due(): void
    {
        $this->enableConfigured();
        config(['features.payment_fix_wave1' => true]);
        $user = User::factory()->create();
        $course = Course::factory()->create();
        [$sub, $commitment, $payment] = $this->paidSale($user, $course, 'SALE-DENY-1');

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-DENY-1',
            'event_type' => 'PAYMENT.SALE.DENIED',
            'resource' => ['id' => 'SALE-DENY-1', 'state' => 'denied'],
        ])->assertOk();

        $this->assertSame('canceled', $payment->fresh()->status);
        $this->assertSame(BillingCommitmentStatus::Failed, $commitment->fresh()->status);
        $this->assertSame(BillingSubscriptionStatus::PastDue, $sub->fresh()->status);
    }

    /** @test */
    public function h5007_refund_flag_off_stays_unmatched_and_keeps_payment_paid(): void
    {
        $this->enableConfigured();
        config(['features.payment_fix_wave1' => false]);
        $user = User::factory()->create();
        $course = Course::factory()->create();
        [, , $payment] = $this->paidSale($user, $course);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-REFUND-OFF',
            'event_type' => 'PAYMENT.SALE.REFUNDED',
            'resource' => ['id' => 'REFUND-OFF', 'sale_id' => 'SALE-REV-1'],
        ])->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(
            PaymentWebhookEvent::DECISION_UNMATCHED,
            PaymentWebhookEvent::where('bank_status', 'PAYMENT.SALE.REFUNDED')->value('decision')
        );
    }

    /** @test */
    public function h5007_updated_event_never_resurrects_cancelled_subscription_when_wave1_on(): void
    {
        // Audit H4 (16-09-2026): UPDATED mapped unconditionally to Active.
        $this->enableConfigured();
        config(['features.payment_fix_wave1' => true]);
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $sub = $this->makePaypalSub($user, $course);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-CANCEL-1',
            'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
            'resource' => ['id' => $sub->provider_subscription_id, 'status' => 'CANCELLED', 'update_time' => '2026-09-16T10:00:00Z'],
        ])->assertOk();
        $this->assertSame(BillingSubscriptionStatus::Cancelled, $sub->fresh()->status);

        // Late UPDATED carrying ACTIVE (older update_time) — rejected, status stays.
        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-UPD-LATE',
            'event_type' => 'BILLING.SUBSCRIPTION.UPDATED',
            'resource' => ['id' => $sub->provider_subscription_id, 'status' => 'ACTIVE', 'update_time' => '2026-09-16T09:00:00Z'],
        ])->assertOk();
        $this->assertSame(BillingSubscriptionStatus::Cancelled, $sub->fresh()->status);
        $this->assertSame(
            PaymentWebhookEvent::DECISION_REJECTED_RESURRECTION,
            PaymentWebhookEvent::where('bank_status', 'BILLING.SUBSCRIPTION.UPDATED')->value('decision')
        );

        // Even a NEWER ACTIVATED cannot revive a terminal subscription.
        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-ACT-LATE',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource' => ['id' => $sub->provider_subscription_id, 'status' => 'ACTIVE', 'update_time' => '2026-09-16T11:00:00Z'],
        ])->assertOk();
        $this->assertSame(BillingSubscriptionStatus::Cancelled, $sub->fresh()->status);
    }

    /** @test */
    public function h5007_updated_event_maps_suspended_status_and_stale_event_is_ignored(): void
    {
        $this->enableConfigured();
        config(['features.payment_fix_wave1' => true]);
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $sub = $this->makePaypalSub($user, $course);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-ACT-2',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource' => ['id' => $sub->provider_subscription_id, 'status' => 'ACTIVE', 'update_time' => '2026-09-16T10:00:00Z'],
        ])->assertOk();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->fresh()->status);

        // UPDATED with SUSPENDED and a newer time → past_due (status from resource, not "Active").
        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-UPD-SUSP',
            'event_type' => 'BILLING.SUBSCRIPTION.UPDATED',
            'resource' => ['id' => $sub->provider_subscription_id, 'status' => 'SUSPENDED', 'update_time' => '2026-09-16T12:00:00Z'],
        ])->assertOk();
        $this->assertSame(BillingSubscriptionStatus::PastDue, $sub->fresh()->status);

        // Stale ACTIVE UPDATED (older than the applied SUSPENDED) is not applied.
        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVT-UPD-STALE',
            'event_type' => 'BILLING.SUBSCRIPTION.UPDATED',
            'resource' => ['id' => $sub->provider_subscription_id, 'status' => 'ACTIVE', 'update_time' => '2026-09-16T11:00:00Z'],
        ])->assertOk();
        $this->assertSame(BillingSubscriptionStatus::PastDue, $sub->fresh()->status);
    }
}
