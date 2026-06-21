<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Tariff;
use App\Models\Teacher;
use App\Services\Bot\CourseCatalogProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CourseCatalogProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_catalog_lists_active_course_with_live_prices(): void
    {
        $teacher = Teacher::create(['name' => 'Екатерина Костина']);
        $course = Course::factory()->live()->create([
            'title' => 'Мегхадута Калидасы',
            'teacher_id' => $teacher->id,
        ]);
        Tariff::factory()->create(['course_id' => $course->id, 'type' => 'full', 'price' => 16500]);
        Tariff::factory()->block(1)->create(['course_id' => $course->id, 'price' => 4800]);
        Tariff::factory()->block(2)->create(['course_id' => $course->id, 'price' => 4800]);

        $md = app(CourseCatalogProvider::class)->markdown();

        $this->assertStringContainsString('Мегхадута Калидасы', $md);
        $this->assertStringContainsString('Екатерина Костина', $md);
        $this->assertStringContainsString('живые занятия', $md);
        $this->assertStringContainsString('16 500 ₽', $md);   // весь курс
        $this->assertStringContainsString('4 800 ₽ за блок', $md); // блоки одной цены — сжато
    }

    public function test_hidden_or_inactive_courses_are_excluded(): void
    {
        Course::factory()->create(['title' => 'Видимый курс']);
        Course::factory()->hidden()->create(['title' => 'Скрытый курс']);
        Course::factory()->create(['title' => 'Неактивный курс', 'is_active' => false]);

        $md = app(CourseCatalogProvider::class)->markdown();

        $this->assertStringContainsString('Видимый курс', $md);
        $this->assertStringNotContainsString('Скрытый курс', $md);
        $this->assertStringNotContainsString('Неактивный курс', $md);
    }

    public function test_blocks_with_different_prices_are_listed_separately(): void
    {
        $course = Course::factory()->create(['title' => 'Курс с разными ценами']);
        Tariff::factory()->block(1)->create(['course_id' => $course->id, 'price' => 4800]);
        Tariff::factory()->block(2)->create(['course_id' => $course->id, 'price' => 3000]);

        $md = app(CourseCatalogProvider::class)->markdown();

        $this->assertStringContainsString('№1 — 4 800 ₽', $md);
        $this->assertStringContainsString('№2 — 3 000 ₽', $md);
    }

    public function test_block_dates_render_when_present(): void
    {
        $course = Course::factory()->create(['title' => 'Курс с датами']);
        CourseBlock::factory()->withDates(now()->setDate(2026, 2, 1), now()->setDate(2026, 5, 30))
            ->create(['course_id' => $course->id, 'number' => 1]);

        $md = app(CourseCatalogProvider::class)->markdown();

        $this->assertStringContainsString('01.02.2026', $md);
        $this->assertStringContainsString('30.05.2026', $md);
    }
}
