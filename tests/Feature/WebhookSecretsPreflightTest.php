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
}
