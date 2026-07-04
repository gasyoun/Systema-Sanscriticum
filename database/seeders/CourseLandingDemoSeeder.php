<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\CourseFaq;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\Testimonial;
use Illuminate\Database\Seeder;

/**
 * Эталонный курс продающей страницы (H154). Заполняет ОДИН курс всеми блоками
 * лендинга для визуальной отладки (blade-styling / Playwright) и как образец.
 *
 * Идемпотентен: повторный запуск обновляет тот же курс по slug, ничего не
 * дублируя. НЕ подключён к DatabaseSeeder — запускать вручную:
 *   php artisan db:seed --class=Database\\Seeders\\CourseLandingDemoSeeder
 */
class CourseLandingDemoSeeder extends Seeder
{
    public const SLUG = 'grammatika-sanskrita-demo';

    public function run(): void
    {
        $teacher = Teacher::firstOrCreate(
            ['name' => 'Мария Кочергина (демо)'],
            ['bio' => '<p>Преподаёт санскрит более 20 лет в традиции живых учителей. Автор нескольких пособий по грамматике.</p>']
        );

        $course = Course::updateOrCreate(
            ['slug' => self::SLUG],
            [
                'title' => 'Грамматика санскрита по Кочергиной (демо)',
                'description' => '<p>Полный курс классической санскритской грамматики: от деванагари и сандхи до именного и глагольного словоизменения. Занятия с разбором примеров из первоисточников.</p>',
                'is_visible' => true,
                'is_active' => true,
                'format' => 'recorded',
                'teacher_id' => $teacher->id,
                'lessons_count' => 24,
                'hours_count' => 48,
                'audience' => [
                    'Начинающие, кто хочет читать санскрит с нуля',
                    'Студенты-индологи и религиоведы',
                    'Практикующие йогу и ведантистов, желающие работать с первоисточниками',
                ],
                'outcomes' => [
                    'Свободно читать и писать деванагари',
                    'Применять правила сандхи',
                    'Разбирать именное словоизменение по всем падежам',
                    'Определять глагольные формы настоящего времени',
                ],
                'meta_title' => 'Грамматика санскрита онлайн — курс по Кочергиной',
                'meta_description' => 'Полный онлайн-курс грамматики санскрита с нуля: деванагари, сандхи, склонение и спряжение. Записи занятий, доступ навсегда.',
            ]
        );

        // Блоки программы.
        foreach ([
            1 => 'Письмо и фонетика',
            2 => 'Именное словоизменение',
            3 => 'Глагол',
        ] as $number => $title) {
            CourseBlock::updateOrCreate(
                ['course_id' => $course->id, 'number' => $number],
                ['title' => $title, 'is_active' => true]
            );
        }

        // Preview-урок (публичный «Пример урока»).
        Lesson::updateOrCreate(
            ['course_id' => $course->id, 'slug' => self::SLUG.'-preview'],
            [
                'title' => 'Вводное занятие: алфавит деванагари',
                'block_number' => 1,
                'lesson_date' => now(),
                'is_published' => true,
                'is_preview' => true,
                'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ]
        );

        // FAQ.
        $faqs = [
            ['Нужна ли предварительная подготовка?', 'Нет. Курс начинается с самых основ — с алфавита деванагари.'],
            ['Останется ли доступ к записям?', 'Да, доступ к видеозаписям остаётся с вами навсегда.'],
            ['На каком языке ведётся курс?', 'Полностью на русском языке.'],
        ];
        $course->faqs()->delete();
        foreach ($faqs as $i => [$q, $a]) {
            CourseFaq::create([
                'course_id' => $course->id,
                'question' => $q,
                'answer' => $a,
                'sort_order' => $i,
            ]);
        }

        // Отзывы (библиотека + пивот).
        $testimonials = [
            ['Анна', 'Москва', 'Наконец-то смогла начать читать «Бхагавад-гиту» в оригинале. Спасибо!'],
            ['Дмитрий', 'Санкт-Петербург', 'Очень системная подача. Сандхи перестали быть загадкой.'],
            ['Ольга', 'Казань', 'Записи выручают — пересматриваю сложные темы столько, сколько нужно.'],
        ];
        $ids = [];
        foreach ($testimonials as $i => [$name, $city, $body]) {
            $t = Testimonial::updateOrCreate(
                ['author_name' => $name, 'body' => $body],
                ['city' => $city, 'rating' => 5]
            );
            $ids[$t->id] = ['sort_order' => $i];
        }
        $course->testimonials()->sync($ids);

        // Тарифы: весь курс + отдельный блок (для наглядного блока «Тарифы»).
        \App\Models\Tariff::updateOrCreate(
            ['course_id' => $course->id, 'type' => 'full', 'block_number' => null],
            ['title' => 'Весь курс', 'price' => 24000, 'old_price' => 30000, 'is_active' => true,
                'description' => 'Полный доступ ко всем модулям и записям навсегда.']
        );
        \App\Models\Tariff::updateOrCreate(
            ['course_id' => $course->id, 'type' => 'block', 'block_number' => 1, 'block_half' => null],
            ['title' => 'Блок 1 · Письмо и фонетика', 'price' => 9000, 'is_active' => true]
        );

        $this->command?->info('Demo landing course seeded: /online/kursy/'.self::SLUG);
    }
}
