<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ScheduleLabel;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * MG 28-09-2026: единое оформление подписей на /raspisanie.
 */
class ScheduleLabelTest extends TestCase
{
    /** @test */
    public function strips_current_year_but_keeps_other_years(): void
    {
        $this->assertSame(
            'Йога-сутры Патанджали, вт 15:00',
            ScheduleLabel::displayTitle('Йога-сутры Патанджали, вторник 15:00 (2026)', 2026)
        );
        $this->assertSame(
            'Грамматика хинди №5, вт (2025)',
            ScheduleLabel::displayTitle('Грамматика хинди гр. 5, вторник (2025)', 2026)
        );
        $this->assertSame(
            'Избранные главы из Бхагавадгиты 4 цикл (2025-2026)',
            ScheduleLabel::displayTitle('Избранные главы из Бхагавадгиты 4 цикл (2025-2026)', 2026)
        );
    }

    /** @test */
    public function names_grammar_subject_and_number(): void
    {
        $this->assertSame(
            'Грамматика санскрита по Кочергиной №61',
            ScheduleLabel::displayTitle('Грамматика по Кочергиной гр.61', 2026)
        );
        $this->assertSame(
            'Грамматика санскрита по Бюллеру №27',
            ScheduleLabel::displayTitle('Грамматика по Бюллеру гр.27', 2026)
        );
        $this->assertSame(
            'Грамматика хинди №1, ср 8:00',
            ScheduleLabel::displayTitle('Грамматика хинди гр. 1, среда 8:00 (2026)', 2026)
        );
    }

    /** @test */
    public function abbreviates_weekdays(): void
    {
        $this->assertSame(
            'Что-то, вс 10:00',
            ScheduleLabel::displayTitle('Что-то, воскресенье 10:00 (2026)', 2026)
        );
    }

    /** @test */
    public function reorders_teacher_name(): void
    {
        $this->assertSame(
            'Эдгар Зигфридович Лейтан',
            ScheduleLabel::teacherDisplay('Лейтан Эдгар Зигфридович')
        );
        $this->assertSame(
            'Марцис Юрьевич Гасунс',
            ScheduleLabel::teacherDisplay('Гасунс Марцис Юрьевич')
        );
        $this->assertSame('Санка Уша', ScheduleLabel::teacherDisplay('Санка Уша'));
        $this->assertSame('Уша Санка', ScheduleLabel::teacherDisplay('Уша Санка'));
        $this->assertSame('Платон', ScheduleLabel::teacherDisplay('Платон'));
    }

    /** @test */
    public function builds_next_and_progress_labels(): void
    {
        $next = Carbon::parse('2026-10-05 20:00'); // понедельник, 41-я неделя

        $this->assertSame('пн 20:00', ScheduleLabel::nextLabel($next));
        $this->assertSame('сейчас 6-е (нед. 41)', ScheduleLabel::progressLabel(5, $next));
        $this->assertSame('сейчас 1-е (нед. 41)', ScheduleLabel::progressLabel(0, $next));
        $this->assertNull(ScheduleLabel::nextLabel(null));
        $this->assertNull(ScheduleLabel::progressLabel(5, null));
    }
}
