<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\AttendanceDashboard;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanvaTransferTest extends TestCase
{
    use RefreshDatabase;

    private function grammarCourse(string $title): array
    {
        $course = Course::factory()->create(['title' => $title, 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        return [$course, $group];
    }

    private function lessons(int $courseId, array $chitkaLessons, array $deviationTitles = []): void
    {
        foreach ($chitkaLessons as $i => $lesson) {
            Lesson::create([
                'title' => 'Кочергина '.$lesson.' (читка)',
                'course_id' => $courseId,
                'lesson_date' => now()->subDays(30 - $i)->format('Y-m-d H:i:s'),
            ]);
        }
        foreach ($deviationTitles as $i => $title) {
            Lesson::create([
                'title' => $title,
                'course_id' => $courseId,
                'lesson_date' => now()->subDays(10 - $i)->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /** @test */
    public function transfer_view_lists_groups_by_cursor_with_compatibility(): void
    {
        // Группа A: урок 6; группа B: урок 7 (совместима, Δ=1); группа C: урок 13 (Δ=7 — не совместима с A).
        [$courseA, $groupA] = $this->grammarCourse('Грамматика по Кочергиной гр.60');
        $this->lessons($courseA->id, [1, 2, 3, 4, 5, 6], ['Эмено 1 (тренаж)', 'Зачитка субхашит']);
        [$courseB, $groupB] = $this->grammarCourse('Грамматика по Кочергиной гр.61');
        $groupB->update(['name' => 'гр.61']);
        $this->lessons($courseB->id, [1, 2, 3, 4, 5, 6, 7]);
        [$courseC, $groupC] = $this->grammarCourse('Грамматика по Кочергиной гр.62');
        $groupC->update(['name' => 'гр.62']);
        $this->lessons($courseC->id, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13]);

        $rows = collect((new AttendanceDashboard)->canvasTransfer()['rows']);

        $this->assertSame([13, 7, 6], $rows->pluck('cursor')->all());

        $a = $rows->firstWhere('cursor', 6);
        // Ответвления: 2 записи без предметов канвы.
        $this->assertSame(2, $a['deviations']);
        // Совместимость: B (урок 7) в допуске ±2, C (урок 13) — нет.
        $this->assertSame(1, count($a['compatible']));
        $this->assertStringContainsString('гр.61', $a['compatible'][0]);

        // Сортировка по убыванию курсора: группа C (урок 13) первая.
        $this->assertSame('гр.62', $rows[0]['group']);
    }

    /** @test */
    public function non_canvas_courses_are_excluded_from_transfer(): void
    {
        $course = Course::factory()->create(['title' => 'Философия', 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        Schedule::create(['title' => 'A', 'start' => now()->subDay()->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]);

        $rows = (new AttendanceDashboard)->canvasTransfer()['rows'];
        $this->assertCount(0, $rows);
    }
}
