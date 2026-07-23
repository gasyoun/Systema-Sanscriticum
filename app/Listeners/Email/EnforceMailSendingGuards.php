<?php

declare(strict_types=1);

namespace App\Listeners\Email;

use App\Models\SuppressedEmail;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Global send guard (H1449 A2) — applies to every outgoing Mailable (the 27
 * transactional ones and, later, the campaign engine's CampaignMail) without
 * touching each Mailable individually. Registered on Illuminate\Mail\Events\
 * MessageSending; returning false from the listener cancels the send.
 *
 * Two independent checks:
 *  (a) suppression — any recipient present in `suppressed_emails` cancels
 *      the whole send (a mixed-recipient blast is not expected in this app).
 *  (b) throttle — config('mail.throttle_per_minute') sends per rolling
 *      minute, org-wide (mailbox providers rate-limit/suspend on bulk send —
 *      see docs/mail-esp.md D6 ruling). Once exceeded, the send is dropped,
 *      not delayed/retried (dropping is safer than a silent pile-up).
 */
class EnforceMailSendingGuards
{
    private const THROTTLE_KEY = 'mail-send-throttle';

    public function handle(MessageSending $event): bool
    {
        $recipients = $this->recipientAddresses($event);

        foreach ($recipients as $address) {
            if (SuppressedEmail::isSuppressed($address)) {
                Log::info('Mail send skipped: recipient is suppressed.', ['email' => $address]);

                return false;
            }
        }

        $perMinute = (int) config('mail.throttle_per_minute', 30);
        if ($perMinute > 0 && RateLimiter::tooManyAttempts(self::THROTTLE_KEY, $perMinute)) {
            Log::warning('Mail send skipped: per-minute throttle exceeded.', [
                'limit' => $perMinute,
                'recipients' => $recipients,
            ]);

            return false;
        }

        RateLimiter::hit(self::THROTTLE_KEY, 60);

        return true;
    }

    /**
     * @return list<string>
     */
    private function recipientAddresses(MessageSending $event): array
    {
        $addresses = [];
        foreach ($event->message->getTo() as $to) {
            $addresses[] = mb_strtolower($to->getAddress());
        }

        return $addresses;
    }
}
