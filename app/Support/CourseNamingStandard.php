<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ЕДИНЫЙ СТАНДАРТ названий учебных сущностей: группы / «Название потока {TITLE}»
 * генератора расписания / титул занятия. Введён 19-11-2026 (MG ruling) после
 * инцидента с группой 156: одно семейство курса жило в трёх формах —
 * «Кашмирский шиваизм, часть 3 2026» (группа, 35 строк расписания, урок),
 * «Кашмирский шиваизм ч.2» (уроки 2-й части), «Кашмирский шиваизм | 3-й поток»
 * (заголовок TG-чата). См. docs/STANDING_POLICY_COURSE_NAMING_2026.md.
 *
 * Стандарт: базовое имя + квалификатор В СКОБКАХ + год после скобки:
 *   многотомный курс    «Название (ч. N) YYYY»     — «Кашмирский шиваизм (ч. 3) 2026»
 *   повторный поток     «Название (N поток, YYYY)» — «Традиции толкования упанишад (2 поток, 2026)»
 *   параллельные гр.    «Название гр. N»            — «Грамматика по Кочергиной гр.61»
 *
 * Проверка ловит только ОБЪЕКТИВНЫЕ нарушения (отрицательные формы); исторические
 * строки никто массово не переименовывает — их перечисляет `catalog:check-naming`,
 * а правка остаётся решением человека.
 */
final class CourseNamingStandard
{
    /**
     * Титул занятия, добавляемый генератором: « (#N, dd.mm.yy)».
     * Аудит названия режет его перед проверкой — это не часть имени потока.
     */
    public const LESSON_TAG_PATTERN = '/\s*\(#\d+,\s*[\d.]+\)\s*$/u';

    /**
     * @return list<array{rule: string, message: string}>
     */
    public static function violations(string $name): array
    {
        $name = trim($name);
        $violations = [];

        // «часть 3» / «2 часть» / «(1/3 часть)» в любой форме — вне стандарта даже
        // в скобках: стандартная аббревиатура — «(ч. N)».
        if (preg_match('/(?:\bчасть\s*[:№]?\s*\d)|(?:\b\d+(?:\s*\/\s*\d+)?\s*часть\b)/u', $name) === 1) {
            $violations[] = [
                'rule' => 'chast_spelled_out',
                'message' => '«часть N» не входит в стандарт названий — используйте аббревиатуру в скобках: «(ч. N)», напр. «Кашмирский шиваизм (ч. 3) 2026».',
            ];
        }

        // «ч.2» / «ч. 3» БЕЗ открывающей скобки — должно быть «(ч. N)».
        if (preg_match('/(?<!\()\bч\.\s*\d/u', $name) === 1) {
            $violations[] = [
                'rule' => 'ch_bare',
                'message' => '«ч.N» без скобок — заключите квалификатор в скобки: «(ч. N)», напр. «Кашмирский шиваизм (ч. 3) 2026».',
            ];
        }

        // «3-й поток» / «3 поток» БЕЗ скобок — должно быть «(N поток, YYYY)».
        if (preg_match('/(?<!\()\d+\s*-?й?\s+поток/ui', $name) === 1) {
            $violations[] = [
                'rule' => 'potok_bare',
                'message' => '«N поток» без скобок — заключите квалификатор в скобки: «(N поток, YYYY)», напр. «Традиции толкования упанишад (2 поток, 2026)».',
            ];
        }

        // Разделитель «|» ломает генератор расписания: TemplateRenderer режет
        // шаблон по «|» (title | описание | тег) — пайп в названии уедет в поле описания.
        if (str_contains($name, '|')) {
            $violations[] = [
                'rule' => 'pipe_separator',
                'message' => 'Разделитель «|» запрещён в названиях (TemplateRenderer режет титул занятия по «|») — используйте скобки: «Название (ч. N) YYYY».',
            ];
        }

        return $violations;
    }

    /**
     * База титула занятия без тега «(#N, dd.mm.yy)» — для аудита расписаний и уроков.
     */
    public static function stripLessonTag(string $title): string
    {
        return trim((string) preg_replace(self::LESSON_TAG_PATTERN, '', $title));
    }

    /**
     * Готовое правило для Filament-полей (группа / «Название потока {TITLE}»).
     *
     *     ->rule(fn () => CourseNamingStandard::filamentRule())
     */
    public static function filamentRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            foreach (self::violations((string) $value) as $violation) {
                $fail($violation['message']);
            }
        };
    }
}
