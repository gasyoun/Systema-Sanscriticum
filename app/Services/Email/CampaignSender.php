<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Jobs\SendCampaignRecipient;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SuppressedEmail;
use App\Services\Cabinet\RecoveryStateResolver;
use Illuminate\Support\Str;

/**
 * H1449 B6/B7 — resolves a Campaign's segment into CampaignRecipient rows and
 * dispatches one queued job per recipient; also builds the "догон"
 * (resend-to-non-openers) campaign. Early-returns entirely when
 * `email_campaigns` is OFF — the prod-inert guarantee.
 */
class CampaignSender
{
    public function __construct(
        private readonly CampaignSegmentResolver $segmentResolver,
    ) {}

    /** Creates one CampaignRecipient per resolved, non-suppressed segment member and queues the send. */
    public function send(Campaign $campaign): void
    {
        if (! (bool) config('features.email_campaigns')) {
            return;
        }

        $users = $this->segmentResolver->resolve($campaign->segment);
        $already = $campaign->recipients()
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower((string) $email))
            ->all();
        $isLifecycle = ($campaign->segment['type'] ?? null) === 'lifecycle';
        $recovery = $isLifecycle ? app(RecoveryStateResolver::class) : null;

        foreach ($users as $user) {
            $email = mb_strtolower((string) $user->email);
            if ($email === '' || SuppressedEmail::isSuppressed($email)) {
                continue;
            }
            if (in_array($email, $already, true)) {
                continue;
            }
            if ($recovery !== null && $recovery->resolve($user)->suppressOffers()) {
                continue;
            }

            $recipient = $campaign->recipients()->create([
                'user_id' => $user->id,
                'email' => $email,
                'pixel_token' => (string) Str::uuid(),
            ]);
            $already[] = $email;

            SendCampaignRecipient::dispatch($recipient->id);
        }

        $campaign->update(['status' => Campaign::STATUS_SENDING]);
    }

    /**
     * "Догнать неоткрывших" (Anton's догон): a new Campaign reusing the
     * source's subject/body, targeting only recipients of the source
     * campaign whose `opened_at` is still null. Each new recipient links
     * back via `resend_of_id`.
     */
    public function resend(Campaign $source): Campaign
    {
        $resend = Campaign::query()->create([
            'subject' => $source->subject,
            'body_html' => $source->body_html,
            'segment' => $source->segment,
            'status' => Campaign::STATUS_DRAFT,
        ]);

        if (! (bool) config('features.email_campaigns')) {
            return $resend;
        }

        $nonOpeners = $source->recipients()->whereNull('opened_at')->get();

        foreach ($nonOpeners as $original) {
            if (SuppressedEmail::isSuppressed($original->email)) {
                continue;
            }

            $recipient = $resend->recipients()->create([
                'user_id' => $original->user_id,
                'email' => $original->email,
                'pixel_token' => (string) Str::uuid(),
                'resend_of_id' => $original->id,
            ]);

            SendCampaignRecipient::dispatch($recipient->id);
        }

        $resend->update(['status' => Campaign::STATUS_SENDING]);

        return $resend;
    }
}
