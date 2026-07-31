<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * PayPal Subscriptions API façade (H2027).
 *
 * Phase 0: dark wiring only — enabled/configured checks and base URL selection.
 * No Product/Plan/Subscription create calls until Phase 1 (sandbox) after H2026
 * webhook→Payment patterns are reused.
 *
 * Config: features.paypal_subscriptions + services.paypal.subscriptions.*
 * Manual claim path (services.paypal.enabled / PaypalClaimController) is orthogonal.
 */
final class PaypalSubscriptionsService
{
    public function isEnabled(): bool
    {
        return (bool) config('features.paypal_subscriptions', false)
            && (bool) config('services.paypal.subscriptions.enabled', false);
    }

    /**
     * True when the master flag is on and REST credentials + webhook id are present.
     * Phase 1+ code paths must refuse live network work when this is false.
     */
    public function isConfigured(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $clientId = (string) config('services.paypal.subscriptions.client_id', '');
        $clientSecret = (string) config('services.paypal.subscriptions.client_secret', '');
        $webhookId = (string) config('services.paypal.subscriptions.webhook_id', '');

        return $clientId !== '' && $clientSecret !== '' && $webhookId !== '';
    }

    /**
     * REST host for the active mode (sandbox|live), or explicit base URL override.
     */
    public function apiBaseUrl(): string
    {
        $override = trim((string) config('services.paypal.subscriptions.base_url', ''));
        if ($override !== '') {
            return rtrim($override, '/');
        }

        $mode = strtolower((string) config('services.paypal.subscriptions.mode', 'sandbox'));

        return $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Phase 1 placeholder — must not hit the network while Phase 0 is the ship bar.
     *
     * @throws \RuntimeException always until P1 implements the call
     */
    public function createSandboxPlanPlaceholder(): void
    {
        throw new \RuntimeException(
            'PayPal Subscriptions Product/Plan create is Phase 1 (sandbox). '.
            'Enable only after H2026 webhook→Payment reuse review; flag stays OFF in prod by default.'
        );
    }
}
