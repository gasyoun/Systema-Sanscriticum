<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnonsLinkClick;
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
    public function up26_webinar_campaign_routes_each_creative_to_its_own_destination(): void
    {
        $this->get('/ga/up26-ors-c')->assertRedirect('/webinar-upanishady-2026');

        $this->assertSame([
            'utm_source' => 'telegram_samskrte',
            'utm_medium' => 'owned_channel',
            'utm_campaign' => 'upanishady_webinar_oct_2026',
            'utm_content' => 'u26_c',
            'utm_term' => 'philosophy',
        ], session(config('tracked_links.session_key')));

        $this->flushSession();

        $this->get('/ga/up26-ors-p')->assertRedirect('/k/tolkovaniia-upanisad-2-potok-2026');

        $attribution = session(config('tracked_links.session_key'));
        $this->assertSame('upanishady_webinar_oct_2026', $attribution['utm_campaign']);
        $this->assertSame('u26_p', $attribution['utm_content']);
    }

    /** @test */
    public function san26_campaign_keys_route_to_the_zhdun_course_card(): void
    {
        foreach ([
            'san26-ors-c' => 'san26_c',
            'san26-ors-t' => 'san26_t',
            'san26-ors-h' => 'san26_h',
        ] as $token => $content) {
            $this->flushSession();
            $this->get('/ga/'.$token)->assertRedirect('/k/filosofiia-sankhi');

            $attribution = session(config('tracked_links.session_key'));
            $this->assertSame('telegram_samskrte', $attribution['utm_source'], "{$token} source");
            $this->assertSame('owned_channel', $attribution['utm_medium'], "{$token} medium");
            $this->assertSame('sankhya_kurs_oct_2026', $attribution['utm_campaign'], "{$token} campaign");
            $this->assertSame($content, $attribution['utm_content'], "{$token} content");
            $this->assertSame('philosophy', $attribution['utm_term'], "{$token} term");
        }
    }

    /** @test */
    public function beginner_intro_channel_link_leads_to_the_free_first_step_and_keeps_source(): void
    {
        $this->get('/ga/bi26-ors-c')->assertRedirect('/online/poprobovat');

        $this->assertSame([
            'utm_source' => 'telegram_samskrte',
            'utm_medium' => 'owned_channel',
            'utm_campaign' => 'beginner_intro_oct_2026',
            'utm_content' => 'bi26_c',
            'utm_term' => 'beginner',
        ], session(config('tracked_links.session_key')));
    }

    /** @test */
    public function vas26_open_lesson_keys_route_to_the_vasishtha_course_card(): void
    {
        foreach ([
            'vas26-ors-c' => 'vas26_c',
            'vas26-ors-t' => 'vas26_t',
            'vas26-ors-h' => 'vas26_h',
        ] as $token => $content) {
            $this->flushSession();
            $this->get('/ga/'.$token)->assertRedirect('/k/yoga-vasistha');

            $attribution = session(config('tracked_links.session_key'));
            $this->assertSame('telegram_samskrte', $attribution['utm_source'], "{$token} source");
            $this->assertSame('owned_channel', $attribution['utm_medium'], "{$token} medium");
            $this->assertSame('vasishtha_open_oct_2026', $attribution['utm_campaign'], "{$token} campaign");
            $this->assertSame($content, $attribution['utm_content'], "{$token} content");
            $this->assertSame('slow_reading', $attribution['utm_term'], "{$token} term");
        }
    }

    /** @test */
    public function syn26_open_lesson_keys_route_to_the_syntax_course_card(): void
    {
        foreach ([
            'syn26-ors-c' => 'syn26_c',
            'syn26-ors-t' => 'syn26_t',
            'syn26-ors-h' => 'syn26_h',
        ] as $token => $content) {
            $this->flushSession();
            $this->get('/ga/'.$token)->assertRedirect('/k/sintaksis-sanskrita');

            $attribution = session(config('tracked_links.session_key'));
            $this->assertSame('telegram_samskrte', $attribution['utm_source'], "{$token} source");
            $this->assertSame('owned_channel', $attribution['utm_medium'], "{$token} medium");
            $this->assertSame('syntax_open_oct_2026', $attribution['utm_campaign'], "{$token} campaign");
            $this->assertSame($content, $attribution['utm_content'], "{$token} content");
            $this->assertSame('advanced', $attribution['utm_term'], "{$token} term");
        }
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

    /** @test */
    public function every_demo_fixture_key_keeps_its_own_attribution(): void
    {
        $sources = [
            'ors' => ['demo_ors', 'owned_channel'],
            'mg' => ['demo_mg', 'owned_channel'],
            'is' => ['demo_is', 'paid_post'],
            'it' => ['demo_it', 'partner_post'],
            'vk' => ['demo_vk', 'broadcast'],
            'samskrte' => ['demo_samskrte', 'crosslink'],
            'samskrtam' => ['demo_samskrtam', 'crosslink'],
        ];

        foreach ($sources as $channel => [$source, $medium]) {
            foreach (['s', 'v', 'c', 't', 'h'] as $creative) {
                $this->flushSession();
                $this->get("/ga/demo-{$channel}-{$creative}")->assertRedirect('/online');

                $attribution = session(config('tracked_links.session_key'));
                $this->assertSame($source, $attribution['utm_source'], "demo-{$channel}-{$creative} source");
                $this->assertSame($medium, $attribution['utm_medium'], "demo-{$channel}-{$creative} medium");
                $this->assertSame('attribution_smoke_demo', $attribution['utm_campaign'], "demo-{$channel}-{$creative} campaign");
                $this->assertSame("demo_{$creative}", $attribution['utm_content'], "demo-{$channel}-{$creative} content");
                $this->assertSame('smoke', $attribution['utm_term'], "demo-{$channel}-{$creative} term");
            }
        }
    }

    /** @test */
    public function zhdun_deep_link_overlay_redirects_to_bot_and_logs_click(): void
    {
        $this->get('/ga/filosofiya-sankhyi')
            ->assertRedirect('https://t.me/samskrte_bot?start=wl_filosofiya-sankhyi');

        $click = AnonsLinkClick::query()->where('link', 'filosofiya-sankhyi')->first();
        $this->assertNotNull($click, 'клик прослойки должен логироваться до перехода в бота');
        $this->assertSame('zhdun_share', $click->utm['utm_source']);
        $this->assertSame('bot_deeplink', $click->utm['utm_medium']);
        $this->assertSame('filosofiya-sankhyi', $click->utm['utm_content']);
    }

    /** @test */
    public function every_listed_zhdun_course_has_a_tracked_overlay(): void
    {
        foreach ([
            'likbez-po-lingvistike', 'lingvisticheskie-zadachi', 'kalligrafiya-devanagari',
            'nachalnyi-sanskrit-litvinenko', 'gitarthasangraha-abhinavagputy', 'filosofiya-sankhyi',
            'indiiskii-epos', 'kashmirskii-shivaizm', 'putevoditel-po-buddizmu',
            'bxagavadgita-po-rukopisyam', 'arkhitektura-i-iskusstvo-buddizma', 'yoga-i-tantra',
            'grammatika-sanskrita-jan2027', 'kosmografiya',
        ] as $slug) {
            $this->get('/ga/'.$slug)
                ->assertRedirect('https://t.me/samskrte_bot?start=wl_'.$slug);
            $this->flushSession();
        }

        $this->assertSame(14, AnonsLinkClick::query()
            ->where('utm->utm_source', 'zhdun_share')
            ->count());
    }

    /** @test */
    public function volunteer_overlay_redirects_to_the_private_group_and_logs_click(): void
    {
        $this->get('/ga/volunteer')
            ->assertRedirect('https://t.me/+zl3hUJ632t03OGY6');

        $click = AnonsLinkClick::query()->where('link', 'volunteer')->first();
        $this->assertNotNull($click, 'клик по добровольческой прослойке должен логироваться до перехода в группу');
        $this->assertSame('zhdun_volunteer_evergreen', $click->utm['utm_campaign']);
    }

    /** @test */
    public function volunteer_anons_overlay_keeps_ors_attribution_separate(): void
    {
        $this->get('/ga/dobrovolec')
            ->assertRedirect('https://t.me/+zl3hUJ632t03OGY6');

        $click = AnonsLinkClick::query()->where('link', 'dobrovolec')->first();
        $this->assertNotNull($click, 'клик анонсной прослойки должен логироваться до перехода в группу');
        $this->assertSame('telegram_samskrte', $click->utm['utm_source'], 'площадка «Оповещений» несёт свой источник, не zhdun_share');
        $this->assertSame('sanskrit_volunteer_evergreen', $click->utm['utm_campaign']);
    }
}
