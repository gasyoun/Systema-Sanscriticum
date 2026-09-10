<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\MarathonController;
use App\Models\LandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

        // Beat 2 — the three days, verbatim (shared A/B block).
        foreach ($copy['days'] as $day) {
            $response->assertSee($day['title']);
            $response->assertSee($day['body']);
        }

        // Beat 3 — route + schedule answers are the shared FAQ rows verbatim.
        $response->assertSee($copy['faq'][0]['a']);
        $response->assertSee($copy['faq'][2]['a']);

        // Beat 3 — numbers come from config, not literals.
        $response->assertSee((string) config('marathon.paid_track_price'));
        $response->assertSee((string) config('marathon.coupon_amount'));

        // Beat 1 — quiz illustration uses the approved quizGoal labels.
        foreach (MarathonController::QUIZ_GOALS as $label) {
            $response->assertSee($label);
        }
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
