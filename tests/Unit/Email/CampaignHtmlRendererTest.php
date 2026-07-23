<?php

declare(strict_types=1);

namespace Tests\Unit\Email;

use App\Models\Campaign;
use App\Services\Email\CampaignHtmlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignHtmlRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_rewrites_links_and_appends_open_pixel(): void
    {
        $campaign = Campaign::query()->create([
            'subject' => 'S',
            'body_html' => '<p>Hi <a href="https://example.com/course">Записаться</a></p>',
            'segment' => ['type' => 'all_subscribers'],
        ]);
        $recipient = $campaign->recipients()->create([
            'email' => 'r@example.com',
            'pixel_token' => 'the-token',
        ]);

        $rendered = (new CampaignHtmlRenderer)->render($campaign->body_html, $recipient);

        $this->assertStringContainsString('/e/c/the-token/', $rendered);
        $this->assertStringNotContainsString('href="https://example.com/course"', $rendered);
        $this->assertStringContainsString('/e/o/the-token.gif', $rendered);
        $this->assertStringContainsString('Записаться', $rendered);
    }

    public function test_leaves_non_http_links_untouched(): void
    {
        $campaign = Campaign::query()->create([
            'subject' => 'S',
            'body_html' => '<p><a href="mailto:hi@example.com">Написать</a></p>',
            'segment' => ['type' => 'all_subscribers'],
        ]);
        $recipient = $campaign->recipients()->create([
            'email' => 'r@example.com',
            'pixel_token' => 'tok2',
        ]);

        $rendered = (new CampaignHtmlRenderer)->render($campaign->body_html, $recipient);

        $this->assertStringContainsString('href="mailto:hi@example.com"', $rendered);
    }
}
