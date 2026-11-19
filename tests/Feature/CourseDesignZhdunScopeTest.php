<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\CourseDesignAssets;
use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\User;
use App\Services\Design\CourseDesignAssetService;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Курсы из ждуна (позиция на витрине /online/zhdun) попадают в «Дизайн курсов»,
 * хотя ещё неактивны: их обложка уже на витрине, дизайнеру они нужны до старта.
 */
class CourseDesignZhdunScopeTest extends TestCase
{
    use RefreshDatabase;

    private function waitlist(?Course $course, array $overrides = []): CourseWaitlistItem
    {
        static $n = 0;
        $n++;

        return CourseWaitlistItem::create(array_merge([
            'slug' => 'zhdun-design-'.$n,
            'course_title' => $course?->title ?? 'Курс без привязки '.$n,
            'course_id' => $course?->id,
            'teacher_name' => 'Преподаватель',
            'min_payers' => 8,
            'kind' => 'other',
        ], $overrides));
    }

    private function actAsManager(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::MANAGER]));
    }

    public function test_default_view_shows_active_and_zhdun_courses_but_not_other_inactive(): void
    {
        $active = Course::factory()->create(['title' => 'Активный курс', 'is_active' => true]);
        $zhdun = Course::factory()->create(['title' => 'Курс из ждуна', 'is_active' => false]);
        $archived = Course::factory()->create(['title' => 'Архивный курс', 'is_active' => false]);
        $this->waitlist($zhdun);

        $this->actAsManager();

        Livewire::test(CourseDesignAssets::class)
            ->assertCanSeeTableRecords([$active, $zhdun])
            ->assertCanNotSeeTableRecords([$archived])
            ->assertSee('Ждун — курс ещё не запущен');
    }

    public function test_closed_scheduled_and_unlisted_waitlist_items_do_not_pull_a_course_in(): void
    {
        $closed = Course::factory()->create(['is_active' => false]);
        $scheduled = Course::factory()->create(['is_active' => false]);
        $unlisted = Course::factory()->create(['is_active' => false]);
        $this->waitlist($closed, ['status' => CourseWaitlistItem::STATUS_CLOSED]);
        $this->waitlist($scheduled, ['status' => CourseWaitlistItem::STATUS_SCHEDULED]);
        $this->waitlist($unlisted, ['is_listed' => false]);

        $this->actAsManager();

        Livewire::test(CourseDesignAssets::class)
            ->assertCanNotSeeTableRecords([$closed, $scheduled, $unlisted]);
    }

    public function test_zhdun_filter_shows_only_waitlist_courses(): void
    {
        $active = Course::factory()->create(['is_active' => true]);
        $zhdun = Course::factory()->create(['is_active' => false]);
        $this->waitlist($zhdun);

        $this->actAsManager();

        Livewire::test(CourseDesignAssets::class)
            ->filterTable('scope', 'zhdun')
            ->assertCanSeeTableRecords([$zhdun])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_snapshot_counts_zhdun_courses(): void
    {
        Course::factory()->create(['is_active' => true]);
        $zhdun = Course::factory()->create(['is_active' => false]);
        Course::factory()->create(['is_active' => false]);
        $this->waitlist($zhdun);

        $this->assertSame(2, app(CourseDesignAssetService::class)->snapshot()['courses']);
    }

    public function test_waitlist_items_without_a_course_are_listed_above_the_matrix(): void
    {
        $this->waitlist(null, ['course_title' => 'Будущий курс без привязки']);
        $this->waitlist(null, ['course_title' => 'Закрытая позиция', 'status' => CourseWaitlistItem::STATUS_CLOSED]);

        $this->actAsManager();

        Livewire::test(CourseDesignAssets::class)
            ->assertSee('В ждуне без привязанного курса: 1')
            ->assertSee('Будущий курс без привязки')
            ->assertDontSee('Закрытая позиция');
    }
}
