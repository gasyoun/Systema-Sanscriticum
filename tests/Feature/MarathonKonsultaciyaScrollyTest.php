<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\MarathonController;
use App\Models\LandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * H4521 — «Как проходит консультация» scrollytelling block on skin b.
 *
 * Storyboard: marketing/marathon-2026-08/redesign/STORYBOARD_konsultaciya-
 * scrolly_10.09.26.md. Contract under test: flag OFF by default (copy A/B is
 * live until 2026-11-01), `?scrolly=1` QA override shows it, skin b only, and
 * every rendered string/number is verbatim config copy (no new marketing
 * claims, copy A/B config untouched).
 */
class MarathonKonsultaciyaScrollyTest extends TestCase
{
    use RefreshDatabase;

    private const BLOCK_ID = 'scrolly-konsultaciya';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        LandingPage::create([
            'title' => 'Консультация по онлайн-курсам ОРС',
            'slug' => config('marathon.landing_slug'),
            'is_active' => true,
        ]);
    }

    public function test_config_flag_defaults_to_off(): void
    {
        $this->assertFalse((bool) config('marathon_visual.scrollytelling'));
    }

    public function test_block_is_absent_by_default(): void
    {
        $response = $this->get(route('marathon.show'));

        $response->assertOk();
        $response->assertDontSee(self::BLOCK_ID, false);
        $response->assertDontSee('scrolly_step_1', false);
    }

    public function test_query_override_renders_the_block_and_wires_goals(): void
    {
        $response = $this->get(route('marathon.show', ['scrolly' => '1']));

        $response->assertOk();
        $response->assertSee(self::BLOCK_ID, false);
        // Analytics goals 1–3 (beat 4 reuses the existing lead goal).
        $response->assertSee('scrolly_step_', false);
        $response->assertSee("'scrolly_step_' + step", false);
        $response->assertSee('data-scrolly-beat="1"', false);
        $response->assertSee('data-scrolly-beat="3"', false);
        // Beat headlines from the storyboard.
        $response->assertSee('Не знаете свой уровень — это и есть повод прийти');
        $response->assertSee('Три дня: что реально будет');
        $response->assertSee('После консультации: маршрут, цена, расписание');
        $response->assertSee('Записаться на консультацию');
        // CTA anchor points at the existing form.
        $response->assertSee('href="#marathon-form"', false);
        $response->assertSee('id="marathon-form"', false);
    }

    public function test_config_flag_enables_the_block_without_query(): void
    {
        config(['marathon_visual.scrollytelling' => true]);

        $response = $this->get(route('marathon.show'));

        $response->assertOk();
        $response->assertSee(self::BLOCK_ID, false);
    }

    public function test_block_is_skin_b_only(): void
    {
        foreach (['a', 'c', 'd'] as $skin) {
            $response = $this->get(route('marathon.show', ['scrolly' => '1', 'skin' => $skin]));

            $response->assertOk();
            $response->assertDontSee(self::BLOCK_ID, false);
        }
    }

    public function test_block_text_is_verbatim_config_copy_and_config_numbers(): void
    {
        $copy = config('marathon_landing_copy');

        $response = $this->get(route('marathon.show', ['scrolly' => '1']));
        $response->assertOk();

        // Block-SCOPED: every string below also renders elsewhere on the page
        // (days section, FAQ, form select, prices), so a page-wide assertSee
        // would pass even if the block emitted none of this copy. Assert inside
        // the block's own markup only.
        $block = $this->scrollyBlockText($response);
        $this->assertNotSame('', $block, 'scrolly block did not render');

        // Beat 2 — the three days, verbatim (shared A/B block).
        foreach ($copy['days'] as $day) {
            $this->assertStringContainsString($day['title'], $block);
            $this->assertStringContainsString($day['body'], $block);
        }

        // Beat 1 + beat 3 — the shared FAQ rows verbatim ([1] entry/level,
        // [0] schedule, [2] route after the course).
        foreach ([1, 0, 2] as $faqIndex) {
            $this->assertStringContainsString($copy['faq'][$faqIndex]['a'], $block);
        }

        // Beat 3 — numbers come from config, not literals.
        $this->assertStringContainsString((string) config('marathon.paid_track_price'), $block);
        $this->assertStringContainsString((string) config('marathon.coupon_amount'), $block);

        // Beat 1 — quiz illustration uses the approved quizGoal labels.
        foreach (MarathonController::QUIZ_GOALS as $label) {
            $this->assertStringContainsString($label, $block);
        }

        // The copy A/B variant blocks themselves must not leak into the block:
        // beat text is the shared A/B-independent rows only.
        foreach (['hero_title', 'hero_subtitle'] as $variantKey) {
            $this->assertStringNotContainsString($copy['variants']['a'][$variantKey], $block);
            $this->assertStringNotContainsString($copy['variants']['b'][$variantKey], $block);
        }
    }

    /** Decoded text of the scrolly block's own <section>, or '' when absent. */
    private function scrollyBlockText(TestResponse $response): string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.(string) $response->getContent());
        libxml_clear_errors();

        $section = (new \DOMXPath($dom))->query('//section[@id="scrolly-konsultaciya"]')->item(0);

        return $section ? html_entity_decode($section->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }

    public function test_block_renders_the_form_untouched_around_it(): void
    {
        $response = $this->get(route('marathon.show', ['scrolly' => '1']));

        $response->assertOk();
        // Consent / track wording is structural copy that must not move.
        $response->assertSee('Формат участия');
        $response->assertSee(route('marathon.register'), false);
    }
}
