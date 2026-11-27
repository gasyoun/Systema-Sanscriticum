<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Ключи кампаний, ведущие на карточку курса (/k/...) — семейство вынесено
 * из TrackedLinkTest (token-size-gate, H8469): m26/up26/san26-механика
 * остаётся в TrackedLinkTest, per-кампанные карточки живут здесь.
 */

class TrackedLinkCourseCardKeysTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function vas26_open_lesson_keys_route_to_the_vasishtha_course_card(): void
    {
        foreach ([
            'vas26-ors-c' => 'vas26_c',
            'vas26-ors-t' => 'vas26_t',
            'vas26-ors-h' => 'vas26_h',
            'vas26-ors-p' => 'vas26_p',
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
    public function vas26_recap_key_routes_to_the_open_lesson_recording_article(): void
    {
        $this->get('/ga/vas26-ors-r')
            ->assertRedirect('https://samskrtam.ru/kurs-joga-vasishtha/');

        $attribution = session(config('tracked_links.session_key'));
        $this->assertSame('telegram_samskrte', $attribution['utm_source'], 'source');
        $this->assertSame('owned_channel', $attribution['utm_medium'], 'medium');
        $this->assertSame('vasishtha_open_oct_2026', $attribution['utm_campaign'], 'campaign');
        $this->assertSame('vas26_r', $attribution['utm_content'], 'content');
        $this->assertSame('slow_reading', $attribution['utm_term'], 'term');
    }

    /** @test */
    public function syn26_open_lesson_keys_route_to_the_syntax_course_card(): void
    {
        foreach ([
            'syn26-ors-c' => 'syn26_c',
            'syn26-ors-t' => 'syn26_t',
            'syn26-ors-h' => 'syn26_h',
            'syn26-ors-p' => 'syn26_p',
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
    public function kal26_waitlist_keys_route_to_the_calligraphy_course_card(): void
    {
        foreach ([
            'kal26-ors-c' => 'kal26_c',
            'kal26-ors-t' => 'kal26_t',
            'kal26-ors-v' => 'kal26_v',
        ] as $token => $content) {
            $this->flushSession();
            $this->get('/ga/'.$token)->assertRedirect('/k/kalligrafiia-devanagari-2026');

            $attribution = session(config('tracked_links.session_key'));
            $this->assertSame('telegram_samskrte', $attribution['utm_source'], "{$token} source");
            $this->assertSame('owned_channel', $attribution['utm_medium'], "{$token} medium");
            $this->assertSame('kalligrafia_devanagari_2026', $attribution['utm_campaign'], "{$token} campaign");
            $this->assertSame($content, $attribution['utm_content'], "{$token} content");
            $this->assertSame('devanagari', $attribution['utm_term'], "{$token} term");
        }
    }

    /** @test */
    public function ksh3_overview_lesson_keys_route_to_the_kashmir_shaivism_course_card(): void
    {
        foreach ([
            'ksh3-ors-c' => 'ksh3_c',
            'ksh3-ors-t' => 'ksh3_t',
            'ksh3-ors-h' => 'ksh3_h',
        ] as $token => $content) {
            $this->flushSession();
            $this->get('/ga/'.$token)->assertRedirect('/k/kasmirskii-sivaizm-3-potok-2026');

            $attribution = session(config('tracked_links.session_key'));
            $this->assertSame('telegram_samskrte', $attribution['utm_source'], "{$token} source");
            $this->assertSame('owned_channel', $attribution['utm_medium'], "{$token} medium");
            $this->assertSame('kashmir_shaivism_open_oct_2026', $attribution['utm_campaign'], "{$token} campaign");
            $this->assertSame($content, $attribution['utm_content'], "{$token} content");
            $this->assertSame('overview', $attribution['utm_term'], "{$token} term");
        }

        $this->get('/ga/ksh3-ors-p')->assertNotFound();
    }

    /** @test */
    public function sl25_inst_post_key_routes_to_the_sanskrit_literature_recorded_course(): void
    {
        $this->flushSession();
        $this->get('/ga/sl25-inst-p')->assertRedirect('/k/sanskritskaia-literatura-2025');

        $attribution = session(config('tracked_links.session_key'));
        $this->assertSame('telegram_institutsanskrita', $attribution['utm_source'], 'source');
        $this->assertSame('owned_channel', $attribution['utm_medium'], 'medium');
        $this->assertSame('sanskritskaia_literatura_recorded', $attribution['utm_campaign'], 'campaign');
        $this->assertSame('sl25_p', $attribution['utm_content'], 'content');
        $this->assertSame('literature', $attribution['utm_term'], 'term');

        // Немонтированные ключи той же кампании обязаны держать 404 (см. ksh3-ors-p).
        $this->get('/ga/sl25-inst-c')->assertNotFound();
        $this->get('/ga/sl25-ors-p')->assertNotFound();
    }

    /** @test */
    public function ksh3_story_key_routes_to_the_course_card_with_story_attribution(): void
    {
        $this->get('/ga/ksh3-rs-st-v-20261029-01')
            ->assertRedirect('/k/kasmirskii-sivaizm-3-potok-2026');

        $this->assertSame([
            'utm_source' => 'telegram_rusamskrtam',
            'utm_medium' => 'story',
            'utm_campaign' => 'kashmir_shaivism_open_oct_2026',
            'utm_content' => 'v_story_20261029_01',
            'utm_term' => 'overview',
        ], session(config('tracked_links.session_key')));
    }
}
