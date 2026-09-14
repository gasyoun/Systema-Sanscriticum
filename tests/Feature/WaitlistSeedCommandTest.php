<?php

namespace Tests\Feature;

use App\Models\CourseWaitlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сидр списка ожидания: один Бюлер (MG 01-09-2026: недобор = перенос даты,
 * не вторая строка) и сквозная нумерация хинди (гр. 6/7 после гр. 1-5).
 */
class WaitlistSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_single_bueler_and_numbered_hindi(): void
    {
        $this->artisan('waitlist:seed')->assertSuccessful();

        $this->assertSame(1, CourseWaitlistItem::query()->where('slug', 'like', 'bueler%')->count());
        $this->assertNull(CourseWaitlistItem::query()->where('slug', 'bueler-2-potok')->first());
        $this->assertSame(
            'Начальный хинди (гр. 6)',
            CourseWaitlistItem::query()->where('slug', 'nachalnyi-hindi-1')->value('course_title')
        );
        $this->assertSame(
            'Начальный хинди (гр. 7)',
            CourseWaitlistItem::query()->where('slug', 'nachalnyi-hindi-2')->value('course_title')
        );
    }

    public function test_reseed_collapses_existing_bueler_duplicate(): void
    {
        // Состояние прода до фикса: обе «потоковые» строки уже в таблице
        CourseWaitlistItem::query()->create(['slug' => 'bueler-1-potok', 'course_title' => 'Руководство по Бюлеру', 'teacher_name' => 'Марцис Гасунс']);
        CourseWaitlistItem::query()->create(['slug' => 'bueler-2-potok', 'course_title' => 'Руководство по Бюлеру', 'teacher_name' => 'Марцис Гасунс']);

        $this->artisan('waitlist:seed')->assertSuccessful();

        $this->assertSame(1, CourseWaitlistItem::query()->where('slug', 'like', 'bueler%')->count());
    }

    public function test_reseed_is_idempotent(): void
    {
        $this->artisan('waitlist:seed')->assertSuccessful();
        $before = CourseWaitlistItem::query()->count();
        $this->assertGreaterThan(0, $before);

        $this->artisan('waitlist:seed')->assertSuccessful();
        $this->assertSame($before, CourseWaitlistItem::query()->count());
    }
}
