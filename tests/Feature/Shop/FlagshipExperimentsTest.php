<?php

namespace Tests\Feature\Shop;

use App\Livewire\Shop\CourseCatalog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\StorefrontAnalyticsEvent;
use App\Models\Tariff;
use App\Services\Activity\StorefrontAnalytics;
use App\Support\FlagshipExperiments;
use App\Support\FlagshipLanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H2762 — isolated next-step + CTA A/B on Kochergina only.
 */
class FlagshipExperimentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15 12:00:00');
        config([
            'features.catalog_next_step' => false,
            'features.flagship_cta_ab' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_flags_off_hide_next_step_and_keep_control_cta(): void
    {
        $course = $this->makeKocherginaWithPreview();
        Course::factory()->create([
            'slug' => 'hindi-1-sr800-2026',
            'title' => 'Грамматика хинди',
            'is_visible' => true,
        ]);

        Livewire::test(CourseCatalog::class)
            ->assertDontSee('data-analytics="next-step"', false)
            ->assertDontSee('После этого', false);

        $this->get(route('shop.course.show', $course->slug))
            ->assertOk()
            ->assertSee('Смотреть пробный урок')
            ->assertDontSee('data-cta-ab=', false);

        $this->assertSame(0, StorefrontAnalyticsEvent::query()->count());
    }

    public function test_next_step_appears_only_on_the_named_flagship_card(): void
    {
        config(['features.catalog_next_step' => true]);

        $flagship = $this->makeKocherginaWithPreview();
        Course::factory()->create([
            'slug' => 'hindi-1-sr800-2026',
            'title' => 'Грамматика хинди',
            'is_visible' => true,
        ]);
        Course::factory()->create([
            'slug' => FlagshipLanding::SMOKE_SLUGS['buhler'],
            'title' => 'Грамматика по Бюллеру гр.27',
            'is_visible' => true,
        ]);

        Livewire::test(CourseCatalog::class)
            ->assertSee('data-analytics="next-step"', false)
            ->assertSee('После этого')
            ->assertSee('Бюлер')
            ->assertSee('тексты')
            ->assertSee('рецитация')
            ->assertSee(route('shop.next-step', ['target' => 'buhler', 'from' => $flagship->id]), false);

        $this->assertSame(1, StorefrontAnalyticsEvent::query()->where('event_name', StorefrontAnalyticsEvent::CARD_IMPRESSION)->count());
        $this->assertSame('next_step', StorefrontAnalyticsEvent::query()->first()->experiment);
    }

    public function test_next_step_click_logs_and_redirects(): void
    {
        config(['features.catalog_next_step' => true]);

        $from = $this->makeKocherginaWithPreview();
        Course::factory()->create([
            'slug' => FlagshipLanding::SMOKE_SLUGS['buhler'],
            'title' => 'Грамматика по Бюллеру гр.27',
            'is_visible' => true,
        ]);

        $this->get(route('shop.next-step', ['target' => 'buhler', 'from' => $from->id]))
            ->assertRedirect(route('shop.course.show', FlagshipLanding::SMOKE_SLUGS['buhler']));

        $row = StorefrontAnalyticsEvent::query()->where('event_name', StorefrontAnalyticsEvent::NEXT_STEP_CLICK)->first();
        $this->assertNotNull($row);
        $this->assertSame('buhler', $row->target);
        $this->assertSame($from->id, $row->course_id);
        $this->assertSame('kochergina', $row->flagship_key);
    }

    public function test_unknown_next_step_target_is_404(): void
    {
        $this->get('/online/next-step/price')->assertNotFound();
    }

    public function test_cta_ab_changes_only_the_label_on_kochergina(): void
    {
        config(['features.flagship_cta_ab' => true]);

        $flagship = $this->makeKocherginaWithPreview();
        $other = Course::factory()->create([
            'slug' => 'hindi-1-sr800-2026',
            'title' => 'Грамматика хинди',
            'is_visible' => true,
        ]);
        Lesson::factory()->create([
            'course_id' => $other->id,
            'is_preview' => true,
            'is_published' => true,
            'title' => 'Хинди превью',
        ]);

        $this->withCookie(FlagshipExperiments::CTA_COOKIE, 'b')
            ->get(route('shop.course.show', $flagship->slug))
            ->assertOk()
            ->assertSee('Записаться')
            ->assertSee('data-cta-ab="b"', false)
            ->assertSee('href="#sample"', false);

        $this->withCookie(FlagshipExperiments::CTA_COOKIE, 'b')
            ->get(route('shop.course.show', $other->slug))
            ->assertOk()
            ->assertSee('Смотреть пробный урок')
            ->assertDontSee('data-cta-ab=', false);
    }

    public function test_cta_control_arm_keeps_the_previous_string(): void
    {
        config(['features.flagship_cta_ab' => true]);

        $flagship = $this->makeKocherginaWithPreview();

        $this->withCookie(FlagshipExperiments::CTA_COOKIE, 'a')
            ->get(route('shop.course.show', $flagship->slug))
            ->assertOk()
            ->assertSee('Смотреть пробный урок')
            ->assertSee('data-cta-ab="a"', false);
    }

    public function test_sample_play_and_begin_checkout_are_logged_for_the_flagship(): void
    {
        config(['features.flagship_cta_ab' => true]);

        $flagship = $this->makeKocherginaWithPreview();
        $tariff = Tariff::factory()->for($flagship)->create(['is_active' => true]);

        $this->withCookie(FlagshipExperiments::CTA_COOKIE, 'a')
            ->get(route('shop.course.preview', $flagship->slug))
            ->assertOk();

        $this->withCookie(FlagshipExperiments::CTA_COOKIE, 'a')
            ->get(route('checkout.show', $tariff))
            ->assertOk();

        $this->assertSame(1, StorefrontAnalyticsEvent::query()->where('event_name', StorefrontAnalyticsEvent::SAMPLE_PLAY)->count());
        $this->assertSame(1, StorefrontAnalyticsEvent::query()->where('event_name', StorefrontAnalyticsEvent::BEGIN_CHECKOUT)->count());
        $this->assertSame(
            ['a'],
            StorefrontAnalyticsEvent::query()->pluck('variant')->unique()->values()->all()
        );
    }

    public function test_seven_day_count_query_groups_events(): void
    {
        config(['features.catalog_next_step' => true, 'features.flagship_cta_ab' => true]);

        $course = $this->makeKocherginaWithPreview();
        $this->get(route('shop.next-step', ['target' => 'texts', 'from' => $course->id]))->assertRedirect();
        $this->get(route('shop.course.preview', $course->slug))->assertOk();

        $report = app(StorefrontAnalytics::class)->countWindow(7);
        $this->assertSame('kochergina', $report['flagship']);
        $this->assertSame(7, $report['days']);
        $names = collect($report['rows'])->pluck('event_name')->all();
        $this->assertContains(StorefrontAnalyticsEvent::NEXT_STEP_CLICK, $names);
        $this->assertContains(StorefrontAnalyticsEvent::SAMPLE_PLAY, $names);

        $this->artisan('shop:flagship-experiments', ['--days' => 7, '--json' => true])
            ->assertSuccessful();
    }

    public function test_other_flagships_do_not_get_either_test(): void
    {
        config([
            'features.catalog_next_step' => true,
            'features.flagship_cta_ab' => true,
        ]);

        $buhler = Course::factory()->create([
            'slug' => FlagshipLanding::SMOKE_SLUGS['buhler'],
            'title' => 'Грамматика по Бюллеру гр.27',
            'is_visible' => true,
        ]);
        Lesson::factory()->create([
            'course_id' => $buhler->id,
            'is_preview' => true,
            'is_published' => true,
        ]);

        Livewire::test(CourseCatalog::class)
            ->assertDontSee('data-analytics="next-step"', false);

        $this->withCookie(FlagshipExperiments::CTA_COOKIE, 'b')
            ->get(route('shop.course.show', $buhler->slug))
            ->assertOk()
            ->assertSee('Смотреть пробный урок')
            ->assertDontSee('data-cta-ab=', false);
    }

    private function makeKocherginaWithPreview(): Course
    {
        $course = Course::factory()->create([
            'slug' => FlagshipLanding::SMOKE_SLUGS['kochergina'],
            'title' => 'Грамматика по Кочергиной гр.61',
            'is_visible' => true,
        ]);
        Lesson::factory()->create([
            'course_id' => $course->id,
            'is_preview' => true,
            'is_published' => true,
            'title' => 'Вводный урок',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);

        return $course->fresh(['previewLesson']);
    }
}
