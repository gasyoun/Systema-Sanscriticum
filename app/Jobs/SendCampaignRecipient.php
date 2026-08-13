<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\CampaignRecipient;
use App\Models\SuppressedEmail;
use App\Services\Email\CampaignHtmlRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * H1449 B6 — one queued job per CampaignRecipient. Early-returns entirely
 * when `email_campaigns` is OFF, and again if the recipient is suppressed
 * (A2) — the per-minute throttle itself is enforced globally by
 * App\Listeners\Email\EnforceMailSendingGuards on every Mailable send, this
 * job doesn't duplicate that check.
 */
final class SendCampaignRecipient implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $campaignRecipientId,
    ) {
        $this->onQueue('mailing');
    }

    public function handle(CampaignHtmlRenderer $renderer): void
    {
        if (! (bool) config('features.email_campaigns')) {
            return;
        }

        $recipient = CampaignRecipient::with('campaign')->find($this->campaignRecipientId);
        if ($recipient === null || $recipient->campaign === null) {
            Log::warning('SendCampaignRecipient: recipient or campaign not found.', [
                'campaign_recipient_id' => $this->campaignRecipientId,
            ]);

            return;
        }

        if ($recipient->sent_at !== null) {
            return;
        }

        if (SuppressedEmail::isSuppressed($recipient->email)) {
            return;
        }

        $bodyHtml = $renderer->render($recipient->campaign->body_html, $recipient);

        Mail::to($recipient->email)->send(new CampaignMail($recipient->campaign->subject, $bodyHtml));

        $recipient->updateQuietly(['sent_at' => now()]);
    }
}
