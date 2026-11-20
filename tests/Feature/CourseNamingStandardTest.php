<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Support\CourseNamingStandard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Единый стандарт названий (19-11-2026, MG ruling): инцидент — группа 156
 * «Кашмирский шиваизм, часть 3 2026» уехала титулом в напоминание и записи.
 * Ядро проверки — App\Support\CourseNamingStandard; гейт — Filament-правила
 * на полях «Название группы» и «Название потока {TITLE}» + catalog:check-naming.
 */
class CourseNamingStandardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Позитивный контроль: стандартные формы не дают нарушений.
     */
    public function test_standard_forms_pass(): void
    {
        $standard = [
            'Кашмирский шиваизм (ч. 3) 2026',
            'Традиции толкования упанишад (2 поток, 2026)',
            'Грамматика по Кочергиной гр.61',
            'Продленка санскрита 2026',
            'Избранные главы из Бхагавадгиты 4 цикл',
            'Кашмирский шиваизм (ч. 3) 2026 (#1, 19.11.26)',
        ];

        foreach ($standard as $name) {
            $this->assertSame([], CourseNamingStandard::violations($name), "Не должно быть нарушений: «{$name}»");
        }
    }

    /**
     * Негативный контроль: все формы инцидента ловятся.
     */
    public function test_known_bad_shapes_fail(): void
    {
        $incidents = [
            'Кашмирский шиваизм, часть 3 2026' => 'chast_spelled_out',
            'Напевный санскрит - гимн Бхагавадгиты (2 часть, 2026)' => 'chast_spelled_out',
            'Кашмирский шиваизм ч.2 (#14, 17.06.26)' => 'ch_bare',
            'Кашмирский шиваизм | 3-й поток' => 'pipe_separator',
            'Кашмирский шиваизм 3 поток 2026' => 'potok_bare',
        ];

        foreach ($incidents as $name => $expectedRule) {
            $rules = array_column(CourseNamingStandard::violations($name), 'rule');
            $this->assertContains($expectedRule, $rules, "Форма «{$name}» должна ловиться правилом {$expectedRule}");
        }

        // Заголовок TG-чата из инцидента ловится ДВУМЯ правилами: пайп + голый поток.
        $rules = array_column(CourseNamingStandard::violations('Кашмирский шиваизм | 3-й поток'), 'rule');
        $this->assertContains('potok_bare', $rules);
    }

    /**
     * Тег занятия «(#N, dd.mm.yy)» — не часть названия потока: аудит режет его.
     */
    public function test_lesson_tag_is_stripped(): void
    {
        $this->assertSame(
            'Кашмирский шиваизм (ч. 3) 2026',
            CourseNamingStandard::stripLessonTag('Кашмирский шиваизм (ч. 3) 2026 (#1, 19.11.26)'),
        );
        $this->assertSame(
            'Кашмирский шиваизм (ч. 3) 2026',
            CourseNamingStandard::stripLessonTag('Кашмирский шиваизм (ч. 3) 2026'),
        );
    }

    /**
     * «ч.N»/«N поток» ВНУТРИ скобок — легитимны: правила судят только по тексту
     * вне скобок (инцидент ложных срабатываний 20-11-2026: «(проверка, ч.1)»).
     */
    public function test_qualifier_inside_parens_is_not_a_violation(): void
    {
        $insideParens = [
            'Кочергина 11 (проверка, ч.1) (#16, 02.05.26)',
            'Басня про отшельника и мышь (проверка, ч.2) (#25, 04.07.26)',
            '(проверка, 2 поток)',
        ];

        foreach ($insideParens as $name) {
            $this->assertSame([], CourseNamingStandard::violations($name), "Не должно быть нарушений: «{$name}»");
        }

        // Голые формы остаются нарушениями — вне скобок.
        $this->assertContains('potok_bare', array_column(CourseNamingStandard::violations('Вводное занятие, 51 поток'), 'rule'));
        $this->assertContains('ch_bare', array_column(CourseNamingStandard::violations('Онлайн беседа с Игорем Пантюшевым ч.4'), 'rule'));
    }

    /**
     * Аудит catalog:check-naming: exit 1 и строка по каждому нарушению.
     */
    public function test_audit_command_flags_violations(): void
    {
        $group = Group::create(['name' => 'Кашмирский шиваизм, часть 3 2026']);
        Schedule::create([
            'title' => 'Кашмирский шиваизм, часть 3 2026 (#1, 19.11.26)',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
        ]);
        $course = Course::create(['title' => 'Курс', 'slug' => 'k']);
        Lesson::create(['title' => 'Кашмирский шиваизм ч.2 (#1, 19.11.26)', 'course_id' => $course->id, 'is_published' => true, 'lesson_date' => now()->format('Y-m-d H:i:s')]);

        $this->artisan('catalog:check-naming')
            ->expectsOutputToContain('groups #'.$group->id)
            ->expectsOutputToContain('«ч.N» без скобок')
            ->assertExitCode(1);
    }

    /**
     * Аудит чистой базы: exit 0.
     */
    public function test_audit_command_passes_when_clean(): void
    {
        Group::create(['name' => 'Кашмирский шиваизм (ч. 3) 2026']);

        $this->artisan('catalog:check-naming')
            ->expectsOutputToContain('Нарушений стандарта названий нет.')
            ->assertExitCode(0);
    }

    /**
     * Скоуп --group (гейт запуска новой группы): исторический красный В ДРУГОЙ
     * группе не должен гасить запуск — иначе постоянно-красный гейт приучает
     * игнорировать красное.
     */
    public function test_group_scope_is_immune_to_legacy_violations_elsewhere(): void
    {
        $legacy = Group::create(['name' => 'Кашмирский шиваизм, часть 2 2026']);
        $clean = Group::create(['name' => 'Кашмирский шиваизм (ч. 3) 2026']);
        Schedule::create([
            'title' => 'Кашмирский шиваизм ч.2 (#1, 19.11.26)',
            'start' => now()->addMinutes(10),
            'group_id' => $legacy->id,
        ]);

        // Полный цензус — красный: исторические строки перечислены.
        $this->artisan('catalog:check-naming')->assertExitCode(1);

        // Гейт запуска новой группы — зелёный: её строк в списке нет.
        $this->artisan('catalog:check-naming', ['--group' => $clean->id])
            ->expectsOutputToContain('Нарушений стандарта названий в строках группы #'.$clean->id.' нет.')
            ->assertExitCode(0);

        // Скоуп красной группы — красный только по её строкам.
        $this->artisan('catalog:check-naming', ['--group' => $legacy->id])
            ->expectsOutputToContain('groups #'.$legacy->id)
            ->assertExitCode(1);
    }

    /**
     * Скоуп группы ловит и урока-запись её курса (course_group pivot): у записей
     * потоков group_id бывает пуст, курс привязан через pivot.
     */
    public function test_group_scope_covers_lessons_via_course_pivot(): void
    {
        $group = Group::create(['name' => 'Кашмирский шиваизм (ч. 3) 2026']);
        $course = Course::create(['title' => 'Курс', 'slug' => 'k']);
        DB::table('course_group')->insert(['course_id' => $course->id, 'group_id' => $group->id]);
        Lesson::create([
            'title' => 'Кашмирский шиваизм ч.2 (#1, 19.11.26)',
            'course_id' => $course->id,
            'is_published' => true,
            'lesson_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $this->artisan('catalog:check-naming', ['--group' => $group->id])
            ->expectsOutputToContain('«ч.N» без скобок')
            ->assertExitCode(1);
    }
}
