<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Deterministic, network-free deployment gate for inbound webhook secrets. */
class WebhookSecretsPreflight extends Command
{
    protected $signature = 'deploy:webhook-preflight';

    protected $description = 'Fail deployment when required inbound webhook secrets are missing.';

    public function handle(): int
    {
        $failures = [];

        if (trim((string) config('services.zoom.webhook_secret', '')) === '') {
            $failures[] = 'ZOOM_WEBHOOK_SECRET is required.';
        }

        if ((bool) config('partner.enabled', false)
            && trim((string) config('partner.bot_secret', '')) === ''
        ) {
            $failures[] = 'PARTNER_BOT_SECRET is required when PARTNER_PROGRAM_ENABLED=true.';
        }

        // H2304 spec 4: the signature-verification escape hatch turns every
        // unsigned POST into a valid money event — allowed in local only.
        if ((bool) config('services.paypal.subscriptions.skip_signature_verify', false)
            && config('app.env') !== 'local'
        ) {
            $failures[] = 'PAYPAL_SKIP_WEBHOOK_SIGNATURE=true is forbidden outside APP_ENV=local.';
        }

        if ((bool) config('features.paypal_subscriptions', false)
            && (bool) config('services.paypal.subscriptions.enabled', false)
        ) {
            foreach ([
                'webhook_id' => 'PAYPAL_WEBHOOK_ID',
                'client_id' => 'PAYPAL_CLIENT_ID',
                'client_secret' => 'PAYPAL_CLIENT_SECRET',
            ] as $key => $envName) {
                if (trim((string) config("services.paypal.subscriptions.{$key}", '')) === '') {
                    $failures[] = "{$envName} is required when PayPal Subscriptions are enabled.";
                }
            }
        }

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        if ($failures !== []) {
            $this->error('deploy:webhook-preflight FAILED.');

            return self::FAILURE;
        }

        $this->info('deploy:webhook-preflight OK.');

        return self::SUCCESS;
    }
}
