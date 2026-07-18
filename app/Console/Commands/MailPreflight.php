<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Deploy-gating guard for #504: prod once inherited `.env.example`'s dev
 * mailpit host — a mail-catcher with no outbound relay, so every mail was
 * silently swallowed. Deterministic and network-free by default; `--send`
 * is the only check that touches the network. See docs/mail-esp.md.
 */
class MailPreflight extends Command
{
    protected $signature = 'mail:preflight {--send= : Send one real test email to the given address (opt-in, touches the network)}';

    protected $description = 'Guard against a dev mail config (mailpit host / placeholder sender) reaching production.';

    private const DEV_HOSTS = ['mailpit', 'localhost', '127.0.0.1'];

    public function handle(): int
    {
        $failures = [];

        if (! app()->environment('local')) {
            $host = Str::lower((string) config('mail.mailers.smtp.host'));
            if ($host !== '' && Str::contains($host, self::DEV_HOSTS)) {
                $failures[] = "MAIL_HOST is '{$host}', a dev mail-catcher with no outbound relay — point it at your ESP (see docs/mail-esp.md).";
            }

            $from = Str::lower((string) config('mail.from.address'));
            if (Str::endsWith($from, 'example.com')) {
                $failures[] = "MAIL_FROM_ADDRESS is '{$from}', a placeholder sender — use a verified address on an SPF+DKIM+DMARC domain (see docs/mail-esp.md).";
            }
        }

        $queueConnection = (string) config('queue.default');
        if ($queueConnection !== 'sync') {
            $this->warn("QUEUE_CONNECTION is '{$queueConnection}' — a worker must consume the 'mailing' queue or no mail will send (php artisan queue:work --queue=mailing).");
        }

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        if ($failures !== []) {
            $this->error('mail:preflight FAILED — production would inherit a dev mail config. See docs/mail-esp.md.');

            return self::FAILURE;
        }

        $this->info('mail:preflight OK.');

        if ($address = $this->option('send')) {
            Mail::raw('This is a test email from `php artisan mail:preflight --send`.', function ($message) use ($address): void {
                $message->to($address)->subject('Systema mail:preflight test');
            });
            $this->info("Test email dispatched to {$address}.");
        }

        return self::SUCCESS;
    }
}
