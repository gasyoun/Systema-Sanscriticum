<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Livewire\Shop\CourseCatalog;
use App\Models\Course;
use App\Models\CourseDesignAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H4310 (2/3) — плашка курса из course_design_assets на карточке каталога.
 *
 * Приоритет: сданный куратору баннер формата 4:3 (аспект карточки) — если
 * дизайнер его не сдал, карточка молча падает на прежнее поведение
 * (courses.image_path), regression не должен появиться.
 */
class CourseCatalogBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /** @test */
    public function catalog_card_prefers_the_designer_4x3_badge_over_the_showcase_cover(): void
    {
        $course = Course::factory()->create([
            'title' => 'Badge Priority Course',
            'image_path' => 'covers/legacy-cover.jpg',
        ]);

        Storage::disk('public')->put('covers/legacy-cover.jpg', 'legacy');
        Storage::disk('public')->put('course-design/badge.jpg', 'badge');

        CourseDesignAsset::create([
            'course_id' => $course->id,
            'format' => '4:3',
            'disk' => 'public',
            'path' => 'course-design/badge.jpg',
        ]);

        $badgeUrl = Storage::disk('public')->url('course-design/badge.jpg');
        $legacyUrl = Storage::disk('public')->url('covers/legacy-cover.jpg');

        $this->assertSame($badgeUrl, $course->fresh()->catalogBadgeUrl());

        Livewire::test(CourseCatalog::class)
            ->assertSee($badgeUrl, false)
            ->assertDontSee($legacyUrl, false);
    }

    /** @test */
    public function catalog_card_falls_back_to_the_showcase_cover_when_no_badge_is_submitted(): void
    {
        $course = Course::factory()->create([
            'title' => 'No Badge Course',
            'image_path' => 'covers/legacy-cover.jpg',
        ]);

        Storage::disk('public')->put('covers/legacy-cover.jpg', 'legacy');

        $legacyUrl = Storage::disk('public')->url('covers/legacy-cover.jpg');

        $this->assertSame($legacyUrl, $course->fresh()->catalogBadgeUrl());

        Livewire::test(CourseCatalog::class)
            ->assertSee($legacyUrl, false);
    }

    /** @test */
    public function catalog_card_ignores_a_non_4x3_badge_format(): void
    {
        $course = Course::factory()->create([
            'title' => 'Wrong Format Course',
            'image_path' => 'covers/legacy-cover.jpg',
        ]);

        Storage::disk('public')->put('covers/legacy-cover.jpg', 'legacy');
        Storage::disk('public')->put('course-design/wide.jpg', 'wide');

        CourseDesignAsset::create([
            'course_id' => $course->id,
            'format' => '16:9',
            'disk' => 'public',
            'path' => 'course-design/wide.jpg',
        ]);

        $legacyUrl = Storage::disk('public')->url('covers/legacy-cover.jpg');

        $this->assertSame($legacyUrl, $course->fresh()->catalogBadgeUrl());
    }
}
