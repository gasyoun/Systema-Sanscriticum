<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Http\Request;

/**
 * PayPal webhook signature check (H2027 Phase 1).
 * Production: POST /v1/notifications/verify-webhook-signature.
 * Tests: bind AcceptingPaypalWebhookSignatureVerifier or set
 * services.paypal.subscriptions.skip_signature_verify=true.
 */
interface PaypalWebhookSignatureVerifier
{
    public function verify(Request $request): bool;
}
