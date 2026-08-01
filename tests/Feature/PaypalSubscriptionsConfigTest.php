<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Services\Payments\PaypalSubscriptionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2027 Phase 0: PayPal Subscriptions dark flag + empty wiring.
 * Claim path must stay independent (see PaypalClaimTest).
 */
class PaypalSubscriptionsConfigTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function subscriptions_flag_defaults_off(): void
    {
        // Fresh config from env default (tests may not set PAYPAL_SUBSCRIPTIONS_ENABLED).
        $this->assertFalse((bool) config('features.paypal_subscriptions'));
        $this->assertFalse((bool) config('services.paypal.subscriptions.enabled'));

        $svc = app(PaypalSubscriptionsService::class);
        $this->assertFalse($svc->isEnabled());
        $this->assertFalse($svc->isConfigured());
    }

    /** @test */
    public function webhook_returns_404_when_disabled(): void
    {
        config([
            'features.paypal_subscriptions' => false,
            'services.paypal.subscriptions.enabled' => false,
        ]);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
        ])->assertNotFound();
    }

    /** @test */
    public function webhook_returns_503_when_enabled_but_unconfigured(): void
    {
        config([
            'features.paypal_subscriptions' => true,
            'services.paypal.subscriptions.enabled' => true,
            'services.paypal.subscriptions.client_id' => '',
            'services.paypal.subscriptions.client_secret' => '',
            'services.paypal.subscriptions.webhook_id' => '',
        ]);

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
        ])->assertStatus(503);
    }

    /** @test */
    public function webhook_returns_200_when_enabled_and_configured_p1(): void
    {
        config([
            'features.paypal_subscriptions' => true,
            'services.paypal.subscriptions.enabled' => true,
            'services.paypal.subscriptions.client_id' => 'test-client',
            'services.paypal.subscriptions.client_secret' => 'test-secret',
            'services.paypal.subscriptions.webhook_id' => 'WH-test',
            'services.paypal.subscriptions.mode' => 'sandbox',
        ]);

        $svc = app(PaypalSubscriptionsService::class);
        $this->assertTrue($svc->isEnabled());
        $this->assertTrue($svc->isConfigured());
        $this->assertStringContainsString('sandbox', $svc->apiBaseUrl());

        // P1: signature accepted (skip/test double), unmatched subscription → 200 + ledger.
        config(['services.paypal.subscriptions.skip_signature_verify' => true]);
        $this->app->bind(
            \App\Services\Payments\PaypalWebhookSignatureVerifier::class,
            \App\Services\Payments\AcceptingPaypalWebhookSignatureVerifier::class,
        );

        $this->postJson('/api/webhooks/paypal-subscriptions', [
            'id' => 'WH-EVENT-1',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource' => ['id' => 'I-UNKNOWN'],
        ])->assertOk();
    }

    /** @test */
    public function provider_constant_for_subscription_charges_is_distinct_from_claim(): void
    {
        $this->assertSame('paypal', Payment::PROVIDER_PAYPAL);
        $this->assertSame('paypal_subscription', Payment::PROVIDER_PAYPAL_SUBSCRIPTION);
        $this->assertContains(Payment::PROVIDER_PAYPAL, Payment::MANUAL_CLAIM_PROVIDERS);
        $this->assertNotContains(
            Payment::PROVIDER_PAYPAL_SUBSCRIPTION,
            Payment::MANUAL_CLAIM_PROVIDERS
        );
    }

    /** @test */
    public function claim_path_still_404_when_claim_disabled_subscriptions_flag_irrelevant(): void
    {
        config([
            'services.paypal.enabled' => false,
            'features.paypal_subscriptions' => true,
            'services.paypal.subscriptions.enabled' => true,
            'services.paypal.subscriptions.client_id' => 'x',
            'services.paypal.subscriptions.client_secret' => 'y',
            'services.paypal.subscriptions.webhook_id' => 'z',
        ]);

        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->block(2)->create(['price' => 4800]);

        $this->get(route('paypal.claim.show', $tariff))->assertNotFound();
    }
}
