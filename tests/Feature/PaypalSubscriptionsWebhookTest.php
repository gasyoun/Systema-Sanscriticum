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
        ])->assertStatus(500);

        $this->assertSame(0, Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->count());
        $this->assertSame(0, PaymentWebhookEvent::where('provider', 'paypal')->count());
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
        ])->assertStatus(500);

        $this->assertSame(0, Payment::where('provider', Payment::PROVIDER_PAYPAL_SUBSCRIPTION)->count());
        $this->assertSame(2, BillingCommitment::where('status', BillingCommitmentStatus::Scheduled)->count());
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
}
