<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Support\FlagshipExperiments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FlagshipExperimentsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_named_flagship_is_kochergina(): void
    {
        $this->assertSame('kochergina', FlagshipExperiments::flagshipKey());
        $this->assertTrue(FlagshipExperiments::isFlagship(
            Course::factory()->make(['slug' => 'grammatika-po-kocerginoi-gr61', 'title' => 'Грамматика'])
        ));
        $this->assertFalse(FlagshipExperiments::isFlagship(
            Course::factory()->make(['slug' => 'start-chteniya', 'title' => 'Старт чтения'])
        ));
    }

    public function test_flags_off_means_both_tests_are_dark(): void
    {
        config(['features.catalog_next_step' => false, 'features.flagship_cta_ab' => false]);

        $this->assertFalse(FlagshipExperiments::nextStepEnabled());
        $this->assertFalse(FlagshipExperiments::ctaAbEnabled());
        $this->assertSame([], FlagshipExperiments::nextStepLinks());
    }

    public function test_closed_window_hides_both_tests_without_shipping_a_winner(): void
    {
        config([
            'features.catalog_next_step' => true,
            'features.flagship_cta_ab' => true,
            'flagship_experiments.started_at' => '2026-06-01',
            'flagship_experiments.window_days' => 30,
        ]);

        $this->assertFalse(FlagshipExperiments::windowOpen());
        $this->assertFalse(FlagshipExperiments::nextStepEnabled());
        $this->assertFalse(FlagshipExperiments::ctaAbEnabled());
    }

    public function test_resolve_target_falls_back_to_catalog_when_missing(): void
    {
        $url = FlagshipExperiments::resolveTargetUrl('buhler');

        $this->assertSame(route('shop.index'), $url);
    }

    public function test_resolve_target_uses_visible_flagship_slug(): void
    {
        Course::factory()->create([
            'slug' => 'grammatika-po-biulleru-gr27',
            'title' => 'Грамматика по Бюллеру',
            'is_visible' => true,
        ]);

        $this->assertSame(
            route('shop.course.show', 'grammatika-po-biulleru-gr27'),
            FlagshipExperiments::resolveTargetUrl('buhler')
        );
    }
}
