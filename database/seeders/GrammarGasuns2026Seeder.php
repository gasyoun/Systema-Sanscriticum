<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Tariff;
use App\Models\Teacher;
use Illuminate\Database\Seeder;

/** Idempotently creates the two-year 2026 grammar intake and sellable offers. */
final class GrammarGasuns2026Seeder extends Seeder
{
    public function run(): void
    {
        $teacher = Teacher::firstOrCreate(['name' => 'Гасунс Марцис Юрьевич'], ['email' => 'rusamskrtam@yandex.ru']);
        $course = Course::updateOrCreate(['slug' => 'grammatika-gasuns-2026'], [
            'title' => 'Грамматика санскрита с нуля — набор 2026', 'teacher_id' => $teacher->id,
            'description' => '<p>Двухлетний онлайн-курс: 25 блоков, от санскритского письма деванагари до систематической грамматики по «Учебнику санскрита» В. А. Кочергиной.</p>',
            'is_visible' => true, 'is_active' => true, 'format' => 'live', 'level' => 'beginner',
            'lessons_count' => 100, 'hours_count' => 150, 'meta_title' => 'Грамматика санскрита онлайн с М. Ю. Гасунсом',
            'meta_description' => 'Новые онлайн-группы грамматики санскрита с нуля: суббота или вторник.',
        ]);
        foreach (range(1, 25) as $n) CourseBlock::updateOrCreate(['course_id' => $course->id, 'number' => $n], ['title' => 'Блок '.$n, 'is_active' => true]);
        foreach ([['Грамматика 2026 — суббота 12:00', '2026-09-19 12:00:00'], ['Грамматика 2026 — вторник 08:00', '2026-09-22 08:00:00']] as [$name, $start]) {
            $group = Group::firstOrCreate(['name' => $name], ['status' => 'forming']);
            $course->groups()->syncWithoutDetaching([$group->id]);
            Schedule::firstOrCreate(['course_id' => $course->id, 'group_id' => $group->id, 'start' => $start], ['title' => $name]);
        }
        foreach (range(1, 25) as $n) Tariff::updateOrCreate(['course_id' => $course->id, 'type' => 'block', 'block_number' => $n, 'block_half' => null], ['title' => 'Блок '.$n, 'price' => 8000, 'is_active' => true]);
        Tariff::updateOrCreate(['course_id' => $course->id, 'type' => 'bundle', 'start_block' => 1, 'end_block' => 12], ['title' => 'Первый учебный год · 12 блоков', 'price' => 96000, 'is_active' => true, 'description' => 'Оплата первого учебного года вперед.']);
        Tariff::updateOrCreate(['course_id' => $course->id, 'type' => 'bundle', 'start_block' => 1, 'end_block' => 25], ['title' => 'Весь курс · 25 блоков', 'price' => 200000, 'is_active' => true, 'description' => 'Оплата двухлетнего курса вперед.']);
    }
}
