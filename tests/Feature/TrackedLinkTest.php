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
}
