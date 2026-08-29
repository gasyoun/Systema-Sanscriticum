<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Schedule;
use App\Support\NextIntroSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FreeIntroBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-07 12:00:00');
        NextIntroSession::flushCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function empty_source_omits_date_line_and_does_not_invent_a_date(): void
    {
        $this->assertNull(NextIntroSession::resolve(useCache: false));
        $this->assertSame(NextIntroSession::FALLBACK_LABEL, NextIntroSession::dateLabel(useCache: false));

        $html = $this->get(route('shop.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-analytics="free-intro-banner"', $html);
        $this->assertStringContainsString('data-intro-source="empty"', $html);
        $this->assertStringContainsString('data-intro-has-date="0"', $html);
        $this->assertStringNotContainsString(NextIntroSession::FALLBACK_LABEL, $html);
        $this->assertStringNotContainsString('Ближайшая дата', $html);
        $this->assertStringContainsString('Смотреть курсы', $html);
        // No invented promotional calendar year in the empty path.
        $this->assertStringNotContainsString('2027', $html);
    }

    /** @test */
    public function trial_schedule_is_preferred_source_and_renders_live_date(): void
    {
        $course = Course::factory()->create([
            'slug' => 'intro-grammar-live',
            'title' => 'Грамматика с нуля (тест)',
            'is_visible' => true,
        ]);
        $start = Carbon::parse('2026-08-15 19:00:00');
        $schedule = Schedule::create([
            'title' => 'Вводное',
            'course_id' => $course->id,
            'start' => $start,
        ]);
        $course->update([
            'trial_price' => 0,
            'trial_schedule_id' => $schedule->id,
        ]);

        $hit = NextIntroSession::resolve(useCache: false);
        $this->assertNotNull($hit);
        $this->assertSame('trial_schedule', $hit['source']);
        $this->assertTrue($hit['is_free']);
        $this->assertSame($schedule->id, $hit['schedule_id']);
        $this->assertStringContainsString('15', $hit['date_label']);

        $html = $this->get(route('shop.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-intro-source="trial_schedule"', $html);
        $this->assertStringContainsString('data-intro-has-date="1"', $html);
        $this->assertStringContainsString($hit['date_label'], $html);
        $this->assertStringContainsString(route('shop.course.show', $course->slug), $html);
    }

    /** @test */
    public function landing_webinar_is_used_when_no_trial_schedule(): void
    {
        LandingPage::create([
            'title' => 'Бесплатный вебинар',
            'slug' => 'free-intro-webinar-h2365',
            'is_active' => true,
            'webinar_label' => 'Бесплатное вводное занятие',
            'webinar_date' => Carbon::parse('2026-09-01 18:30:00'),
        ]);

        $hit = NextIntroSession::resolve(useCache: false);
        $this->assertNotNull($hit);
        $this->assertSame('landing_webinar', $hit['source']);
        $this->assertTrue($hit['is_free']);
        $this->assertSame('free-intro-webinar-h2365', $hit['landing_slug']);

        $html = $this->get(route('shop.start'))->assertOk()->getContent();
        $this->assertStringContainsString('data-intro-source="landing_webinar"', $html);
        $this->assertStringContainsString($hit['date_label'], $html);
        $this->assertStringContainsString(route('promo.show', 'free-intro-webinar-h2365'), $html);
        $this->assertSame(1, mb_substr_count($hit['date_label'], 'сентября'));
        $this->assertStringNotContainsString('сентябрясентября', $hit['date_label']);
    }

    /** @test */
    public function format_date_label_uses_one_russian_month_not_icu_mmmm(): void
    {
        $label = NextIntroSession::formatDateLabel(Carbon::parse('2026-09-01 15:00:00'));

        $this->assertSame(1, mb_substr_count($label, 'сентября'));
        $this->assertStringNotContainsString('сентябрясентября', $label);
        $this->assertStringContainsString('01', $label);
        $this->assertStringContainsString('2026', $label);
        $this->assertStringContainsString('15:00', $label);
    }

    /** @test */
    public function past_trial_and_webinar_do_not_surface(): void
    {
        $course = Course::factory()->create(['slug' => 'past-trial', 'is_visible' => true]);
        $past = Schedule::create([
            'title' => 'Прошлое',
            'course_id' => $course->id,
            'start' => Carbon::parse('2026-07-01 10:00:00'),
        ]);
        $course->update(['trial_price' => 500, 'trial_schedule_id' => $past->id]);

        LandingPage::create([
            'title' => 'Старый вебинар',
            'slug' => 'old-webinar',
            'is_active' => true,
            'webinar_date' => Carbon::parse('2026-06-01 12:00:00'),
        ]);

        $this->assertNull(NextIntroSession::resolve(useCache: false));
        $this->assertSame(
            NextIntroSession::FALLBACK_LABEL,
            NextIntroSession::dateLabel(useCache: false)
        );

        $html = $this->get(route('shop.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString(NextIntroSession::FALLBACK_LABEL, $html);
        $this->assertStringNotContainsString('1 июля', $html);
        $this->assertStringNotContainsString('01 июня', $html);
    }

    /** @test */
    public function for_course_returns_future_trial_only(): void
    {
        $course = Course::factory()->create(['slug' => 'per-course', 'is_visible' => true]);
        $schedule = Schedule::create([
            'title' => 'Курс-вводное',
            'course_id' => $course->id,
            'start' => Carbon::parse('2026-08-20 11:00:00'),
        ]);
        $course->update(['trial_price' => 1000, 'trial_schedule_id' => $schedule->id]);

        $hit = NextIntroSession::forCourse($course->fresh());
        $this->assertNotNull($hit);
        $this->assertSame('trial_schedule', $hit['source']);
        $this->assertFalse($hit['is_free']);
        $this->assertSame('Пробное занятие', $hit['cta_label']);
    }

    /** @test */
    public function banner_partial_has_no_hardcoded_future_date_literals(): void
    {
        $path = resource_path('views/shop/partials/free-intro-banner.blade.php');
        $this->assertFileExists($path);
        $src = file_get_contents($path);
        $this->assertIsString($src);
        // Guard against hard-coded promo dates (year-looking tokens after 2025).
        $this->assertDoesNotMatchRegularExpression('/\b202[6-9]\b/', $src);
        $this->assertDoesNotMatchRegularExpression('/\b20[3-9]\d\b/', $src);
        $this->assertStringContainsString('NextIntroSession', $src);
        $this->assertStringNotContainsString('дата уточняется', $src);
    }
}
