<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Support\CourseNamingStandard;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
