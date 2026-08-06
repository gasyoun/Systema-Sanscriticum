<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class WebhookSecretsPreflightTest extends TestCase
{
    /** @test */
    public function missing_zoom_secret_fails_deterministically(): void
    {
        config([
            'services.zoom.webhook_secret' => '',
            'partner.enabled' => false,
            'partner.bot_secret' => '',
        ]);

        $this->artisan('deploy:webhook-preflight')
            ->assertFailed()
            ->expectsOutputToContain('ZOOM_WEBHOOK_SECRET');
    }

    /** @test */
    public function disabled_partner_program_does_not_require_bot_secret(): void
    {
        config([
            'services.zoom.webhook_secret' => 'zoom-secret',
            'partner.enabled' => false,
            'partner.bot_secret' => '',
        ]);

        $this->artisan('deploy:webhook-preflight')
            ->assertSuccessful()
            ->expectsOutput('deploy:webhook-preflight OK.');
    }

    /** @test */
    public function enabled_partner_program_requires_bot_secret(): void
    {
        config([
            'services.zoom.webhook_secret' => 'zoom-secret',
            'partner.enabled' => true,
            'partner.bot_secret' => '   ',
        ]);

        $this->artisan('deploy:webhook-preflight')
            ->assertFailed()
            ->expectsOutputToContain('PARTNER_BOT_SECRET');
    }

    /** @test */
    public function all_required_secrets_pass(): void
    {
        config([
            'services.zoom.webhook_secret' => 'zoom-secret',
            'partner.enabled' => true,
            'partner.bot_secret' => 'partner-secret',
        ]);

        $this->artisan('deploy:webhook-preflight')
            ->assertSuccessful()
            ->expectsOutput('deploy:webhook-preflight OK.');
    }

    /** @test */
    public function paypal_skip_signature_outside_local_fails(): void
    {
        // H2304 spec 4: эскейп-хэтч превращает любой неподписанный POST в
        // валидное денежное событие — допустим только в APP_ENV=local.
        config([
            'services.zoom.webhook_secret' => 'zoom-secret',
            'partner.enabled' => false,
            'services.paypal.subscriptions.skip_signature_verify' => true,
            'app.env' => 'production',
        ]);

        $this->artisan('deploy:webhook-preflight')
            ->assertFailed()
            ->expectsOutputToContain('PAYPAL_SKIP_WEBHOOK_SIGNATURE');
    }

    /** @test */
    public function paypal_skip_signature_in_local_is_allowed(): void
    {
        config([
            'services.zoom.webhook_secret' => 'zoom-secret',
            'partner.enabled' => false,
            'services.paypal.subscriptions.skip_signature_verify' => true,
            'app.env' => 'local',
        ]);

        $this->artisan('deploy:webhook-preflight')->assertSuccessful();
    }

    /** @test */
    public function enabled_paypal_subscriptions_require_webhook_id_and_secrets(): void
    {
        config([
            'services.zoom.webhook_secret' => 'zoom-secret',
            'partner.enabled' => false,
            'features.paypal_subscriptions' => true,
            'services.paypal.subscriptions.enabled' => true,
            'services.paypal.subscriptions.skip_signature_verify' => false,
            'services.paypal.subscriptions.client_id' => 'cid',
            'services.paypal.subscriptions.client_secret' => '',
            'services.paypal.subscriptions.webhook_id' => '   ',
        ]);

        $this->artisan('deploy:webhook-preflight')
            ->assertFailed()
            ->expectsOutputToContain('PAYPAL_WEBHOOK_ID')
            ->expectsOutputToContain('PAYPAL_CLIENT_SECRET');
    }

    /** @test */
    public function disabled_paypal_subscriptions_do_not_require_secrets(): void
    {
        config([
            'services.zoom.webhook_secret' => 'zoom-secret',
            'partner.enabled' => false,
            'features.paypal_subscriptions' => false,
            'services.paypal.subscriptions.enabled' => false,
            'services.paypal.subscriptions.skip_signature_verify' => false,
            'services.paypal.subscriptions.client_id' => '',
            'services.paypal.subscriptions.client_secret' => '',
            'services.paypal.subscriptions.webhook_id' => '',
        ]);

        $this->artisan('deploy:webhook-preflight')->assertSuccessful();
    }
}
