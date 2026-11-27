<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Часы занятий» в списке студентов: сумма lesson_views.total_time_on_page,
 * сортировка — рейтинг студентов по времени на уроках.
 */
class UserWatchTimeRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_are_ranked_by_hours_on_lesson_pages(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $course = Course::factory()->create();
        $l1 = Lesson::factory()->create(['course_id' => $course->id]);
        $l2 = Lesson::factory()->create(['course_id' => $course->id]);

        $few = User::factory()->create(['name' => 'Мало']);
        $many = User::factory()->create(['name' => 'Много', 'total_time_spent' => 60]);
        $none = User::factory()->create(['name' => 'Ноль', 'total_time_spent' => 999999]);

        $this->watched($few, $l1, 1800);
        $this->watched($many, $l1, 3 * 3600);
        $this->watched($many, $l2, 7 * 60);

        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);

        Livewire::actingAs($admin)->test(ListUsers::class)
            ->sortTable('lesson_views_sum_total_time_on_page', 'desc')
            ->assertCanSeeTableRecords([$many, $few, $none], inOrder: true)
            ->assertSee('3ч 7м')
            ->assertSee('30м');
    }

    public function test_watch_time_format(): void
    {
        $this->assertSame('—', UserResource::formatWatchTime(0));
        $this->assertSame('12м', UserResource::formatWatchTime(12 * 60 + 5));
        $this->assertSame('34ч 7м', UserResource::formatWatchTime(34 * 3600 + 7 * 60));
    }

    private function watched(User $user, Lesson $lesson, int $seconds): void
    {
        LessonView::query()->create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'first_opened_at' => now(),
            'last_opened_at' => now(),
            'open_count' => 1,
            'total_time_on_page' => $seconds,
        ]);
    }
}
