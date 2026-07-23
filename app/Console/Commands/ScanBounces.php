<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SuppressedEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * H1449 A3 — scheduled bounce capture. D11 default: a scheduled IMAP scan of
 * the sending mailbox, chosen over a bounce webhook because mail.ru/Yandex
 * 360 webhook support could not be confirmed without a live spike (S1,
 * docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md). Every hard bounce found is
 * inserted into `suppressed_emails` so the address is never sent to again.
 *
 * Uses PHP's built-in ext-imap directly (no new composer dependency, per the
 * D12 fence against adding heavy packages for this wave). If the extension
 * isn't loaded or `mail.bounce_scan.enabled` is false, the command logs and
 * exits successfully — wiring the real mailbox is an activation-time step
 * (config/mail.php `bounce_scan.*`, see DEPLOY_QUEUE.md).
 */
class ScanBounces extends Command
{
    protected $signature = 'mail:scan-bounces';

    protected $description = 'Scan the sending mailbox for hard bounces and suppress those addresses.';

    public function handle(): int
    {
        if (! (bool) config('mail.bounce_scan.enabled')) {
            $this->info('mail:scan-bounces is disabled (mail.bounce_scan.enabled=false) — no-op.');

            return self::SUCCESS;
        }

        if (! extension_loaded('imap')) {
            $this->warn('mail:scan-bounces: PHP ext-imap is not loaded on this server — no-op. Enable it to activate bounce capture.');
            Log::warning('mail:scan-bounces skipped: ext-imap not loaded.');

            return self::SUCCESS;
        }

        $bounced = $this->scanMailbox();

        foreach ($bounced as $email) {
            SuppressedEmail::suppress($email, 'hard_bounce');
        }

        $this->info(sprintf('mail:scan-bounces: suppressed %d bounced address(es).', count($bounced)));

        return self::SUCCESS;
    }

    /**
     * Isolated so it can be unit-tested without a real mailbox: connects via
     * IMAP, scans for hard-bounce notification messages, extracts the
     * originally-bounced recipient address from each, and returns the list.
     * Real parsing of provider-specific bounce formats (DSN/NDR body,
     * `X-Failed-Recipients` header, etc.) is an activation-time refinement —
     * this method establishes the connection/scan shape.
     *
     * @return list<string>
     */
    protected function scanMailbox(): array
    {
        if (! extension_loaded('imap')) {
            return [];
        }

        $host = (string) config('mail.bounce_scan.host');
        $username = (string) config('mail.bounce_scan.username');
        $password = (string) config('mail.bounce_scan.password');

        if ($host === '' || $username === '') {
            $this->warn('mail:scan-bounces: mail.bounce_scan.host/username not configured — no-op.');

            return [];
        }

        $mailbox = @imap_open($host, $username, $password, OP_READONLY);
        if ($mailbox === false) {
            Log::error('mail:scan-bounces: IMAP connection failed.', ['host' => $host]);

            return [];
        }

        try {
            $bounced = [];
            $messageNumbers = imap_search($mailbox, 'UNSEEN SUBJECT "Undelivered"') ?: [];

            foreach ($messageNumbers as $messageNumber) {
                $headerInfo = imap_headerinfo($mailbox, $messageNumber);
                $failedRecipients = $headerInfo->Xfailedrecipients ?? null;
                if (is_string($failedRecipients) && $failedRecipients !== '') {
                    $bounced[] = mb_strtolower(trim($failedRecipients));
                }
            }

            return array_values(array_unique($bounced));
        } finally {
            imap_close($mailbox);
        }
    }
}
