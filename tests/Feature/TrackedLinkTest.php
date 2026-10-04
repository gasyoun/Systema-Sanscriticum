<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackedLinkTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function campaign_link_redirects_to_a_clean_url_and_keeps_utm_in_the_session(): void
    {
        $this->get('/ga/m26-ors-h')
            ->assertRedirect('/online/kursy/grammatika-gasuns-2026');

        $this->assertSame([
            'utm_source' => 'telegram_samskrte',
            'utm_medium' => 'owned_channel',
            'utm_campaign' => 'grammar_gasuns_autumn_2026',
            'utm_content' => 'g26_h',
            'utm_term' => 'beginner',
        ], session(config('tracked_links.session_key')));
    }

    /** @test */
    public function campaign_link_does_not_overwrite_an_earlier_first_touch(): void
    {
        session([config('tracked_links.session_key') => ['utm_source' => 'earlier']]);

        $this->get('/ga/m26-ors-h')->assertRedirect('/online/kursy/grammatika-gasuns-2026');

        $this->assertSame(['utm_source' => 'earlier'], session(config('tracked_links.session_key')));
    }

    /** @test */
    public function unknown_campaign_link_is_not_a_redirect(): void
    {
        $this->get('/ga/not-a-link')->assertNotFound();
    }

    /** @test */
    public function source_tokens_keep_personal_crosslink_and_senler_attribution_separate(): void
    {
        foreach ([
            'm26-mg-s' => ['telegram_marcisgasuns', 'owned_channel'],
            'm26-samskrte-s' => ['samskrte', 'crosslink'],
            'm26-samskrtam-s' => ['samskrtam', 'crosslink'],
            'm26-vk-s' => ['vk_senler', 'broadcast'],
        ] as $token => [$source, $medium]) {
            $this->flushSession();
            $this->get('/ga/'.$token)->assertRedirect('/online/kursy/grammatika-gasuns-2026');
            $this->assertSame($source, session(config('tracked_links.session_key').'.utm_source'));
            $this->assertSame($medium, session(config('tracked_links.session_key').'.utm_medium'));
        }
    }

    /** @test */
    public function every_story_publication_gets_account_and_instance_specific_attribution(): void
    {
        $this->get('/ga/m26-mg-st-gita-20260917-01')
            ->assertRedirect('/online/kursy/grammatika-gasuns-2026');

        $this->assertSame([
            'utm_source' => 'telegram_marcisgasuns',
            'utm_medium' => 'story',
            'utm_campaign' => 'grammar_gasuns_autumn_2026',
            'utm_content' => 'gita_story_20260917_01',
            'utm_term' => 'beginner',
        ], session(config('tracked_links.session_key')));

        $this->flushSession();
        $this->get('/ga/m26-rs-st-gita-20260917-02')->assertRedirect();
        $this->assertSame('telegram_rusamskrtam', session(config('tracked_links.session_key').'.utm_source'));
        $this->assertSame('gita_story_20260917_02', session(config('tracked_links.session_key').'.utm_content'));
    }

    /** @test */
    public function malformed_or_unknown_story_tokens_are_rejected(): void
    {
        $this->get('/ga/m26-mg-st-gita-20260917-1')->assertNotFound();
        $this->get('/ga/m26-unknown-st-gita-20260917-01')->assertNotFound();
    }

    /** @test */
    public function evergreen_story_series_frames_keep_separate_destinations_and_content(): void
    {
        $this->get('/ga/lingq-rs-st-puzzle-20260917-01')
            ->assertRedirect('https://t.me/samskrtamru/4166');
        $this->assertSame('puzzle_story_20260917_01', session(config('tracked_links.session_key').'.utm_content'));

        $this->flushSession();
        $this->get('/ga/linga-rs-st-answer-20260917-01')
            ->assertRedirect('https://t.me/samskrtamru/4169');
        $this->assertSame('answer_story_20260917_01', session(config('tracked_links.session_key').'.utm_content'));
    }

    /** @test */
    public function demo_smoke_fixture_redirects_and_carries_demo_marked_attribution(): void
    {
        $this->get('/ga/demo-ors-h')->assertRedirect('/online');

        $this->assertSame([
            'utm_source' => 'demo_ors',
            'utm_medium' => 'owned_channel',
            'utm_campaign' => 'attribution_smoke_demo',
            'utm_content' => 'demo_h',
            'utm_term' => 'smoke',
        ], session(config('tracked_links.session_key')));
    }

    /** @test */
    public function demo_story_fixture_resolves_through_story_campaigns(): void
    {
        $this->get('/ga/demo-mg-st-smoke-20261004-01')->assertRedirect('/online');

        $this->assertSame('telegram_marcisgasuns', session(config('tracked_links.session_key').'.utm_source'));
        $this->assertSame('attribution_smoke_demo', session(config('tracked_links.session_key').'.utm_campaign'));
        $this->assertSame('story', session(config('tracked_links.session_key').'.utm_medium'));
        $this->assertSame('smoke_story_20261004_01', session(config('tracked_links.session_key').'.utm_content'));
    }
}
