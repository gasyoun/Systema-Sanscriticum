<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live PayPal verify-webhook-signature (H2027).
 * @see https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature
 */
final class HttpPaypalWebhookSignatureVerifier implements PaypalWebhookSignatureVerifier
{
    public function __construct(
        private readonly PaypalSubscriptionsService $subscriptions,
    ) {}

    public function verify(Request $request): bool
    {
        if ((bool) config('services.paypal.subscriptions.skip_signature_verify', false)) {
            return true;
        }

        $webhookId = (string) config('services.paypal.subscriptions.webhook_id', '');
        $clientId = (string) config('services.paypal.subscriptions.client_id', '');
        $clientSecret = (string) config('services.paypal.subscriptions.client_secret', '');

        if ($webhookId === '' || $clientId === '' || $clientSecret === '') {
            return false;
        }

        $token = $this->accessToken($clientId, $clientSecret);
        if ($token === null) {
            return false;
        }

        $payload = [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO', ''),
            'cert_url' => $request->header('PAYPAL-CERT-URL', ''),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID', ''),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG', ''),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME', ''),
            'webhook_id' => $webhookId,
            'webhook_event' => json_decode($request->getContent(), true) ?? [],
        ];

        $base = $this->subscriptions->apiBaseUrl();
        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post($base.'/v1/notifications/verify-webhook-signature', $payload);

        if (! $response->successful()) {
            Log::warning('paypal_subscriptions.webhook.verify_http_failed', [
                'status' => $response->status(),
            ]);

            return false;
        }

        $status = (string) $response->json('verification_status', '');

        return strtoupper($status) === 'SUCCESS';
    }

    private function accessToken(string $clientId, string $clientSecret): ?string
    {
        $base = $this->subscriptions->apiBaseUrl();
        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->timeout(15)
            ->post($base.'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if (! $response->successful()) {
            Log::warning('paypal_subscriptions.oauth_failed', ['status' => $response->status()]);

            return null;
        }

        $token = $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
