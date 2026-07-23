<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Jobs\SendCampaignRecipient;
use App\Models\Campaign;
use App\Models\SuppressedEmail;
use App\Models\User;
use App\Services\Email\CampaignHtmlRenderer;
use App\Services\Email\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H1449 B6/B7 — CampaignSender::send()/resend() acceptance criteria, all
 * exercised with `email_campaigns` ON as VERIFICATION.md specifies for W1b.
 */
class CampaignSenderTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(): Campaign
    {
        return Campaign::query()->create([
            'subject' => 'Subject',
            'body_html' => '<p>Body <a href="https://example.com">link</a></p>',
            'segment' => ['type' => 'all_subscribers'],
        ]);
    }

    public function test_send_creates_one_recipient_per_segment_member_with_unique_tokens(): void
    {
        Queue::fake();
        config(['features.email_campaigns' => true]);

        User::factory()->count(3)->create(['wants_email_announcements' => true]);
        $campaign = $this->campaign();

        app(CampaignSender::class)->send($campaign);

        $this->assertCount(3, $campaign->recipients);
        $tokens = $campaign->recipients->pluck('pixel_token');
        $this->assertSame($tokens->count(), $tokens->unique()->count());
        Queue::assertPushed(SendCampaignRecipient::class, 3);
    }

    public function test_send_excludes_suppressed_addresses(): void
    {
        Queue::fake();
        config(['features.email_campaigns' => true]);

        $ok = User::factory()->create(['wants_email_announcements' => true, 'email' => 'ok@example.com']);
        $bad = User::factory()->create(['wants_email_announcements' => true, 'email' => 'bad@example.com']);
        SuppressedEmail::suppress('bad@example.com', 'hard_bounce');

        $campaign = $this->campaign();
        app(CampaignSender::class)->send($campaign);

        $this->assertCount(1, $campaign->recipients);
        $this->assertSame('ok@example.com', $campaign->recipients->first()->email);
    }

    public function test_resend_targets_only_non_openers_and_links_resend_of_id(): void
    {
        Queue::fake();
        config(['features.email_campaigns' => true]);

        $opener = User::factory()->create(['wants_email_announcements' => true]);
        $nonOpener = User::factory()->create(['wants_email_announcements' => true]);

        $campaign = $this->campaign();
        $openedRecipient = $campaign->recipients()->create([
            'user_id' => $opener->id,
            'email' => $opener->email,
            'pixel_token' => 'opened-token',
            'opened_at' => now(),
        ]);
        $unopenedRecipient = $campaign->recipients()->create([
            'user_id' => $nonOpener->id,
            'email' => $nonOpener->email,
            'pixel_token' => 'unopened-token',
        ]);

        $resendCampaign = app(CampaignSender::class)->resend($campaign);

        $this->assertCount(1, $resendCampaign->recipients);
        $resent = $resendCampaign->recipients->first();
        $this->assertSame($nonOpener->email, $resent->email);
        $this->assertSame($unopenedRecipient->id, $resent->resend_of_id);
    }

    public function test_prod_inert_when_flag_off_no_recipients_no_dispatch(): void
    {
        Queue::fake();
        config(['features.email_campaigns' => false]);

        User::factory()->count(2)->create(['wants_email_announcements' => true]);
        $campaign = $this->campaign();

        app(CampaignSender::class)->send($campaign);

        $this->assertCount(0, $campaign->recipients()->get());
        Queue::assertNothingPushed();
    }

    public function test_send_campaign_recipient_job_early_returns_when_flag_off(): void
    {
        config(['features.email_campaigns' => true]);
        $campaign = $this->campaign();
        $recipient = $campaign->recipients()->create([
            'email' => 'r@example.com',
            'pixel_token' => 'tok',
        ]);

        config(['features.email_campaigns' => false]);
        (new SendCampaignRecipient($recipient->id))->handle(app(CampaignHtmlRenderer::class));

        $recipient->refresh();
        $this->assertNull($recipient->sent_at);
    }
}
