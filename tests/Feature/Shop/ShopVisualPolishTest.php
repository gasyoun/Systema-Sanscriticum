<?php

namespace Tests\Feature\Shop;

use App\Livewire\Shop\CourseCatalog;
use App\Models\Category;
use App\Models\Course;
use App\Models\Tariff;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H2379 — Arzamas/Синхронизация visual-polish pass:
 * curated entrances above grid, format vocabulary continuity on course hero,
 * recorded-library framing, no empty-state regression on sparse courses.
 */
class ShopVisualPolishTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function catalog_shows_editorial_entrances_above_the_grid(): void
    {
        Course::factory()->live()->create(['title' => 'Live Course Alpha']);
        Course::factory()->create(['title' => 'Recorded Course Beta', 'format' => 'recorded']);

        Livewire::test(CourseCatalog::class)
            ->assertSee('data-analytics="editorial-entrances"', false)
            ->assertSee('Куда зайти')
            ->assertSee('Библиотека записей')
            ->assertSee('Идут сейчас')
            ->assertSee('С нуля');
    }

    /** @test */
    public function recorded_filter_uses_library_vocabulary(): void
    {
        Course::factory()->create(['title' => 'Archive Course Zzz', 'format' => 'recorded']);

        Livewire::test(CourseCatalog::class)
            ->set('format', 'recorded')
            ->assertSee('Библиотека записей')
            ->assertSee('Открытая библиотека');
    }

    /** @test */
    public function editorial_entrances_hide_when_searching(): void
    {
        Course::factory()->create(['title' => 'Searchable Grammar Course']);

        Livewire::test(CourseCatalog::class)
            ->set('search', 'Grammar')
            ->assertDontSee('data-analytics="editorial-entrances"', false);
    }

    /** @test */
    public function live_course_hero_matches_card_format_vocabulary(): void
    {
        $teacher = Teacher::create(['name' => 'Мария Тестова']);
        $course = Course::factory()->live()->create([
            'slug' => 'hero-live-course',
            'title' => 'Героический Live-курс',
            'teacher_id' => $teacher->id,
            'lessons_count' => 8,
            'hours_count' => 6,
            'image_path' => null,
        ]);
        Tariff::factory()->for($course)->create(['is_active' => true]);

        $this->get('/k/'.$course->slug)
            ->assertOk()
            ->assertSee('Идет сейчас')
            ->assertSee('Выбрать тариф')
            ->assertSee('Мария Тестова')
            ->assertSee('Героический Live-курс')
            // Generic-only badge should not be the sole format signal for live.
            ->assertDontSee('Live-поток');
    }

    /** @test */
    public function recorded_course_hero_uses_recorded_badge(): void
    {
        $course = Course::factory()->create([
            'slug' => 'hero-recorded-course',
            'title' => 'Курс в записи Zzz',
            'format' => 'recorded',
            'image_path' => null,
        ]);
        Tariff::factory()->for($course)->create(['is_active' => true]);

        $this->get('/k/'.$course->slug)
            ->assertOk()
            ->assertSee('В записи')
            ->assertSee('Библиотека записей')
            ->assertSee('Выбрать тариф');
    }

    /** @test */
    public function sparse_course_without_image_still_renders_hero_and_tariffs(): void
    {
        $course = Course::factory()->create([
            'slug' => 'sparse-course',
            'title' => 'Разреженный курс',
            'description' => null,
            'image_path' => null,
            'lessons_count' => null,
            'hours_count' => null,
        ]);
        Tariff::factory()->for($course)->create([
            'title' => 'Весь курс',
            'type' => 'full',
            'price' => 12000,
            'is_active' => true,
        ]);

        $this->get('/k/'.$course->slug)
            ->assertOk()
            ->assertSee('Разреженный курс')
            ->assertSee('Выбрать тариф')
            ->assertSee('id="tariffs"', false);
    }

    /** @test */
    public function top_category_entrance_appears_when_categories_exist(): void
    {
        $category = Category::factory()->create([
            'name' => 'Грамматика',
            'is_visible' => true,
        ]);
        $course = Course::factory()->create(['title' => 'Категорийный курс']);
        $course->categories()->attach($category->id);

        Livewire::test(CourseCatalog::class)
            ->assertSee('data-analytics="editorial-entrances"', false)
            ->assertSee('Грамматика');
    }
}
