<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Course;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Направления ("категории") курсов (H1426, decision 7 в PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md):
 * черновой набор, снятый с текущих названий курсов на raspisanie/. Идемпотентно
 * (firstOrCreate по slug). MG пересматривает/переименовывает через существующий
 * CategoryResource после посева — сидер не претендует на финальную таксономию.
 *
 * Категория «санскритская грамматика и морфология» дополнительно привязывается
 * к курсам-кандидатам («Углубленная морфология», «Караки по Панини», «синтаксис
 * санскрита») по slug; курс мог с тех пор переименоваться/пропасть — по
 * ambiguity policy пропускаем не найденное молча (с Log::info), а не падаем.
 */
class CategorySeeder extends Seeder
{
    private const ROWS = [
        ['slug' => 'sanskrit-s-nulya', 'name' => 'Санскрит с нуля'],
        ['slug' => 'sanskrit-prodvinutyj', 'name' => 'Продвинутый санскрит'],
        ['slug' => 'sanskritskaya-grammatika', 'name' => 'Санскритская грамматика и морфология'],
        ['slug' => 'vedijskaya-literatura', 'name' => 'Ведийская литература'],
        ['slug' => 'napevnyj-sanskrit', 'name' => 'Напевный санскрит'],
        ['slug' => 'hindi', 'name' => 'Хинди'],
        ['slug' => 'sanskritskaya-paleografiya', 'name' => 'Санскритская палеография и рукописи'],
        ['slug' => 'ayurveda', 'name' => 'Аюрведа'],
        ['slug' => 'indijskaya-filosofiya', 'name' => 'Индийская философия'],
        ['slug' => 'kashmirskij-shivaizm', 'name' => 'Кашмирский шиваизм'],
        ['slug' => 'kalligrafiya-devanagari', 'name' => 'Каллиграфия деванагари'],
        ['slug' => 'vostochnye-kalendari', 'name' => 'Восточные календари'],
        ['slug' => 'sakralnaya-geografiya', 'name' => 'Сакральная география Индии'],
        ['slug' => 'chtenie-tekstov', 'name' => 'Чтение и перевод текстов'],
        ['slug' => 'lingvistika', 'name' => 'Ликбез по лингвистике'],
    ];

    /** Course.slug кандидатов для «санскритская грамматика и морфология» — не гарантированы, см. class doc. */
    private const GRAMMAR_COURSE_SLUGS = [
        'uglublennaya-morfologiya',
        'karaki-po-panini',
        'sintaksis-sanskrita',
    ];

    public function run(): void
    {
        $bySlug = [];
        foreach (self::ROWS as $row) {
            $bySlug[$row['slug']] = Category::firstOrCreate(
                ['slug' => $row['slug']],
                ['name' => $row['name']]
            );
        }

        $grammar = $bySlug['sanskritskaya-grammatika'];

        foreach (self::GRAMMAR_COURSE_SLUGS as $courseSlug) {
            $course = Course::where('slug', $courseSlug)->first();

            if (! $course) {
                Log::info("CategorySeeder: course slug '{$courseSlug}' not found, skipping grammar-category attach.");

                continue;
            }

            $course->categories()->syncWithoutDetaching([$grammar->id]);
        }
    }
}
