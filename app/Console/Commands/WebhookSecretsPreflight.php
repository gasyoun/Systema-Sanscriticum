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
