<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Http\Request;

/** Test / local double — always accepts (never use in production config). */
final class AcceptingPaypalWebhookSignatureVerifier implements PaypalWebhookSignatureVerifier
{
    public function verify(Request $request): bool
    {
        return true;
    }
}
