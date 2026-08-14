<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\LandingPage;
use App\Support\StorefrontEvent;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StorefrontEventTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function parse_calendar_date_reads_day_month_and_optional_time(): void
    {
        $parsed = StorefrontEvent::parseCalendarDate('БЕСПЛАТНЫЙ ОНЛАЙН-ВЕБИНАР • 17 июня, 18:00 МСК');

        $this->assertNotNull($parsed);
        $this->assertSame('2026-06-17 18:00:00', $parsed->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function past_dated_title_is_relabeled_as_recording(): void
    {
        $landing = new LandingPage([
            'title' => 'БЕСПЛАТНЫЙ ОНЛАЙН-ВЕБИНАР • 17 июня, 18:00 МСК',
            'webinar_label' => 'Бесплатный эфир',
            'webinar_date' => Carbon::parse('2026-06-17 18:00:00'),
            'button_text' => 'Записаться',
        ]);

        $this->assertTrue(StorefrontEvent::isPast($landing));
        $this->assertSame(StorefrontEvent::PAST_WEBINAR_LABEL, StorefrontEvent::storefrontTitle($landing));
        $this->assertSame('Бесплатный эфир', StorefrontEvent::storefrontBadge($landing));
        $this->assertSame('Подробнее', StorefrontEvent::storefrontButtonText($landing));
        $this->assertStringNotContainsString('17 июня', StorefrontEvent::storefrontTitle($landing));
    }

    /** @test */
    public function future_webinar_keeps_title_and_date(): void
    {
        $landing = new LandingPage([
            'title' => 'БЕСПЛАТНЫЙ ОНЛАЙН-ВЕБИНАР • 20 сентября, 18:00 МСК',
            'webinar_label' => 'Бесплатный эфир',
            'webinar_date' => Carbon::parse('2026-09-20 18:00:00'),
            'button_text' => 'Записаться',
        ]);

        $this->assertTrue(StorefrontEvent::isUpcoming($landing));
        $this->assertSame($landing->title, StorefrontEvent::storefrontTitle($landing));
        $this->assertSame('Записаться', StorefrontEvent::storefrontButtonText($landing));
    }

    /** @test */
    public function title_without_a_date_is_kept_even_when_webinar_date_is_past(): void
    {
        $landing = new LandingPage([
            'title' => 'Сакральная география Индии',
            'webinar_date' => Carbon::parse('2026-03-01 12:00:00'),
        ]);

        $this->assertTrue(StorefrontEvent::isPast($landing));
        $this->assertSame('Сакральная география Индии', StorefrontEvent::storefrontTitle($landing));
        $this->assertFalse(StorefrontEvent::isUpcoming($landing));
    }

    /** @test */
    public function dated_title_without_webinar_date_column_is_still_treated_as_past(): void
    {
        $landing = new LandingPage([
            'title' => 'Вебинар 17 июня',
            'webinar_date' => null,
        ]);

        $this->assertTrue(StorefrontEvent::isPast($landing));
        $this->assertSame(StorefrontEvent::PAST_WEBINAR_LABEL, StorefrontEvent::storefrontTitle($landing));
    }
}
