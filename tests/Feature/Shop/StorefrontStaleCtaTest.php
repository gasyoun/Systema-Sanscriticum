<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\Course;
use App\Models\LandingPage;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\Tariff;
use App\Support\NextIntroSession;
use App\Support\StorefrontEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StorefrontStaleCtaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15 12:00:00');
        NextIntroSession::flushCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function homepage_hides_past_webinar_cta_date(): void
    {
        LandingPage::create([
            'title' => 'БЕСПЛАТНЫЙ ОНЛАЙН-ВЕБИНАР • 17 июня, 18:00 МСК',
            'slug' => 'webinar-grammatika',
            'is_active' => true,
            'is_listed' => true,
            'webinar_label' => 'Бесплатный эфир',
            'webinar_date' => Carbon::parse('2026-06-17 18:00:00'),
            'button_text' => 'Записаться',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(StorefrontEvent::PAST_WEBINAR_LABEL, $html);
        $this->assertStringContainsString('webinar-grammatika', $html);
        $this->assertStringNotContainsString('17 июня', $html);
        $this->assertStringNotContainsString('17 июня, 18:00', $html);
    }

    /** @test */
    public function shop_empty_schedule_omits_fallback_date_placeholder(): void
    {
        $html = $this->get(route('shop.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-intro-source="empty"', $html);
        $this->assertStringContainsString('data-intro-has-date="0"', $html);
        $this->assertStringNotContainsString(NextIntroSession::FALLBACK_LABEL, $html);
        $this->assertStringNotContainsString('Ближайшая дата', $html);
        $this->assertStringContainsString('Смотреть курсы', $html);
    }

    /** @test */
    public function course_without_deposit_or_trial_amount_does_not_render_empty_pay(): void
    {
        MarketingSetting::create(['deposit_enabled' => true]);
        $course = Course::factory()->create([
            'slug' => 'empty-pay-course',
            'deposit_amount' => null,
            'trial_price' => null,
            'trial_schedule_id' => null,
        ]);
        Tariff::factory()->for($course)->create(['title' => 'Весь курс', 'price' => 6000]);

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/Оплатить\s*₽/u', $html);
        $this->assertDoesNotMatchRegularExpression('/Сумма\s*₽/u', $html);
        $this->assertStringNotContainsString('Забронируйте место за', $html);
        $this->assertStringNotContainsString('Купить пробное', $html);
    }

    /** @test */
    public function course_with_trial_amount_does_not_emit_empty_pay_in_static_html(): void
    {
        $course = Course::factory()->create([
            'slug' => 'priced-trial-course',
            'trial_price' => 1500,
        ]);
        Tariff::factory()->for($course)->create();
        $session = Schedule::create([
            'title' => 'Пробное',
            'course_id' => $course->id,
            'start' => Carbon::parse('2026-08-20 19:00:00'),
        ]);
        $course->update(['trial_schedule_id' => $session->id]);

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        $this->assertStringContainsString('Купить пробное', $html);
        $this->assertStringContainsString('1 500 ₽', $html);
        $this->assertDoesNotMatchRegularExpression('/Оплатить\s*₽/u', $html);
        $this->assertDoesNotMatchRegularExpression('/Сумма\s*₽ будет зачтена/u', $html);
    }
}
