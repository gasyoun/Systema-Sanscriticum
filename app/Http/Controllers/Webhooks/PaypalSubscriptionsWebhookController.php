<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaypalSubscriptionsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * PayPal Subscriptions webhooks (H2027 Phase 0 stub).
 *
 * Flag OFF → 404 (no surface). Flag ON but not configured → 503.
 * Flag ON + configured → 501 until Phase 1 implements signature verify + ledger
 * + Payment materialisation (mirror H1359 / Tochka webhook discipline).
 *
 * Manual claim path is unaffected (PaypalClaimController).
 */
final class PaypalSubscriptionsWebhookController extends Controller
{
    public function __construct(
        private readonly PaypalSubscriptionsService $subscriptions,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (! $this->subscriptions->isEnabled()) {
            return response('Not Found', 404);
        }

        if (! $this->subscriptions->isConfigured()) {
            Log::warning('paypal_subscriptions.webhook.unconfigured', [
                'path' => $request->path(),
            ]);

            return response('PayPal Subscriptions not configured', 503);
        }

        // Phase 1: verify PAYPAL-TRANSMISSION-* headers against webhook_id,
        // append payment_webhook_events (or paypal-specific ledger), map
        // BILLING.SUBSCRIPTION.* / payment events → billing_subscriptions + Payment.
        Log::info('paypal_subscriptions.webhook.received_stub', [
            'event_type' => $request->input('event_type'),
            'id' => $request->input('id'),
        ]);

        return response('PayPal Subscriptions webhook handler not implemented (Phase 1)', 501);
    }
}
