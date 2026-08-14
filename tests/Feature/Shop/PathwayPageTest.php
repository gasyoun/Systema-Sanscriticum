<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Tariff;
use App\Support\FlagshipLanding;
use App\Support\TrajectoryPathway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * H2764 / R18 — /online/put is a three-track catalogue pathway, not a second
 * warehouse listing and not a why-us clone.
 */
class PathwayPageTest extends TestCase
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

    public function test_pathway_returns_200_with_three_track_headings(): void
    {
        $this->get('/online/put')
            ->assertOk()
            ->assertSee('Письмо и чтение', false)
            ->assertSee('Грамматика', false)
            ->assertSee('Тексты и чанты', false)
            ->assertSee('data-analytics="pathway-tracks"', false);
    }

    public function test_pathway_shows_shop_price_in_rubles(): void
    {
        $course = Course::factory()->create([
            'title' => 'Грамматика санскрита путь',
            'is_visible' => true,
        ]);
        Tariff::factory()->for($course)->create([
            'type' => 'block',
            'price' => 4800,
            'is_active' => true,
        ]);

        $this->get('/online/put')
            ->assertOk()
            ->assertSee('от 4 800 ₽', false);
    }

    public function test_pathway_does_not_clone_why_us_or_the_warehouse(): void
    {
        $this->get('/online/put')
            ->assertOk()
            ->assertDontSee('data-analytics="why-us-block"', false)
            ->assertDontSee('Почему именно мы', false)
            ->assertDontSee('Маленькие группы', false)
            ->assertDontSee('wire:id', false)
            ->assertDontSee('livewire:shop.course-catalog', false);
    }

    public function test_buhler_track_shows_autumn_2027_not_a_2026_intake(): void
    {
        $course = Course::factory()->create([
            'slug' => FlagshipLanding::SMOKE_SLUGS['buhler'],
            'title' => 'Грамматика по Бюлеру гр.27',
            'is_visible' => true,
        ]);
        Tariff::factory()->for($course)->create([
            'type' => 'full',
            'price' => 21000,
            'is_active' => true,
        ]);

        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        Schedule::create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'title' => $course->title,
            'start' => now()->addDays(3),
            'end' => now()->addDays(3)->addHours(2),
        ]);

        $html = $this->get('/online/put')->assertOk()->getContent();

        $this->assertStringContainsString('осень 2027', $html);
        $this->assertStringContainsString(TrajectoryPathway::BUHLER_NEXT_INTAKE_LABEL, $html);
        $this->assertStringNotContainsString('Ближайший поток', $html);
        $this->assertStringNotContainsString('набор 2026', $html);
    }

    public function test_letters_pattern_is_unchanged(): void
    {
        $this->assertSame('Грамматика', config('trajectory.steps.0.pattern'));
        $this->assertSame('letters', config('trajectory.steps.0.key'));
    }

    public function test_shop_index_links_to_the_pathway(): void
    {
        $this->get('/online')
            ->assertOk()
            ->assertSee('/online/put', false)
            ->assertSee('Три направления', false);
    }

    public function test_sitemap_includes_pathway(): void
    {
        Cache::forget('sitemap.xml');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(route('shop.pathway'), false);
    }
}
