<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Http\Controllers\Email\TrackingController;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1449 B4 — open pixel + click redirect: idempotent open, no-open-redirect
 * click, no PII in the URL, and 404 for both when the flag is OFF.
 */
class CampaignTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function recipient(): CampaignRecipient
    {
        $campaign = Campaign::query()->create([
            'subject' => 'S',
            'body_html' => '<p>Hi</p>',
            'segment' => ['type' => 'all_subscribers'],
        ]);

        return $campaign->recipients()->create([
            'email' => 'r@example.com',
            'pixel_token' => 'tok-'.uniqid(),
        ]);
    }

    public function test_open_pixel_stamps_opened_at_once_and_returns_gif(): void
    {
        config(['features.email_campaigns' => true]);
        $recipient = $this->recipient();

        $response = $this->get(route('email.track.open', ['token' => $recipient->pixel_token]));

        $response->assertOk();
        $this->assertSame('image/gif', $response->headers->get('Content-Type'));
        $recipient->refresh();
        $firstOpenedAt = $recipient->opened_at;
        $this->assertNotNull($firstOpenedAt);

        // Second hit must not re-stamp.
        $this->travel(1)->minutes();
        $this->get(route('email.track.open', ['token' => $recipient->pixel_token]));
        $recipient->refresh();
        $this->assertTrue($firstOpenedAt->equalTo($recipient->opened_at));
    }

    public function test_click_stamps_clicked_at_and_redirects_to_decoded_target(): void
    {
        config(['features.email_campaigns' => true]);
        $recipient = $this->recipient();
        $target = 'https://example.com/course/offer';

        $response = $this->get(route('email.track.click', [
            'token' => $recipient->pixel_token,
            'link' => TrackingController::encodeLink($target),
        ]));

        $response->assertRedirect($target);
        $recipient->refresh();
        $this->assertNotNull($recipient->clicked_at);
    }

    public function test_click_with_a_tampered_link_token_is_rejected_not_an_open_redirect(): void
    {
        config(['features.email_campaigns' => true]);
        $recipient = $this->recipient();

        // Not something this app ever encrypted — an attacker-supplied value.
        $response = $this->get(route('email.track.click', [
            'token' => $recipient->pixel_token,
            'link' => 'aHR0cHM6Ly9ldmlsLmV4YW1wbGU',
        ]));

        $response->assertNotFound();
    }

    public function test_tracking_urls_carry_no_pii(): void
    {
        config(['features.email_campaigns' => true]);
        $recipient = $this->recipient();

        $openUrl = route('email.track.open', ['token' => $recipient->pixel_token]);
        $clickUrl = route('email.track.click', [
            'token' => $recipient->pixel_token,
            'link' => TrackingController::encodeLink('https://example.com'),
        ]);

        $this->assertStringNotContainsString($recipient->email, $openUrl);
        $this->assertStringNotContainsString($recipient->email, $clickUrl);
        $this->assertStringNotContainsString('user_id', $openUrl);
        $this->assertStringNotContainsString('user_id', $clickUrl);
    }

    public function test_both_endpoints_404_when_flag_is_off(): void
    {
        config(['features.email_campaigns' => false]);
        $recipient = $this->recipient();

        $this->get(route('email.track.open', ['token' => $recipient->pixel_token]))->assertNotFound();
        $this->get(route('email.track.click', [
            'token' => $recipient->pixel_token,
            'link' => TrackingController::encodeLink('https://example.com'),
        ]))->assertNotFound();
    }
}
