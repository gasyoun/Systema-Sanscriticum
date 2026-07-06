<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\DirectAdSpend;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Tariff;
use App\Support\Money;

/**
 * Юнит-экономика одного урока одного блока: цена урока для ученика, доход
 * преподавателя за урок (факт vs теория), прибыль ИП за урок.
 *
 * Ничего не считает заново — стоит на TeacherSalaryService (выручка и ЗП по
 * блоку) и переиспользует DirectAdSpend (CAC). Здесь только «последняя миля»:
 * комиссия эквайринга по фактическому способу оплаты, УСН 6%, CAC и деление на
 * число уроков в блоке.
 *
 * Разница «факт / теория» ровно та, что важна для процентных преподавателей:
 *  - percent: ЗП начисляется от РЕАЛЬНО оплаченного (должники в базу не входят,
 *    пока не заплатят), теория — как если бы оплатившие заплатили полный прайс;
 *  - фикс за блок: от должников не зависит, факт = теория.
 */
class UnitEconomicsService
{
    public function __construct(private readonly TeacherSalaryService $salary) {}

    /**
     * @return array<string, mixed>  пусто (['found' => false]), если курс/блок не найдены
     */
    public function forBlock(int $courseId, int $blockNumber, ?float $cacOverride = null): array
    {
        $course = Course::find($courseId);
        if (! $course) {
            return ['found' => false];
        }

        $block = CourseBlock::where('course_id', $courseId)->where('number', $blockNumber)->first();

        $tariff = Tariff::where('course_id', $courseId)
            ->where('type', 'block')
            ->where('block_number', $blockNumber)
            ->whereNull('block_half')
            ->first();
        $price = $tariff ? (float) $tariff->price : 0.0;

        $lessons = max(1, Lesson::where('course_id', $courseId)
            ->where('block_number', $blockNumber)->count());

        // Реальная оплаченная выручка блока (после скидок, минус возвраты; должники не входят).
        $detail = $this->salary->blockGroupRevenueDetail($courseId, $blockNumber, null);
        $revenue = (float) $detail['total'];
        $payerLines = array_values(array_filter($detail['lines'], fn ($l) => empty($l['is_return'])));
        $payers = count(array_unique(array_map(fn ($l) => $l['user_id'], $payerLines)));
        $freeStudents = $this->salary->blockFreeStudentCount($courseId, $blockNumber, null);

        // ── Преподаватель и его схема ЗП ──────────────────────────────────────
        $teacher = $course->teacher;
        $terms = $teacher ? $course->salaryTermsFor((int) $teacher->id) : null;
        $type = $terms['type'] ?? null;
        $value = (float) ($terms['value'] ?? 0);
        $totalBlocks = max(1, CourseBlock::where('course_id', $courseId)->count());
        $isPercent = in_array($type, ['percent', 'percent_per_block'], true);

        $teacherFact = match ($type) {
            'percent', 'percent_per_block' => $revenue * $value / 100,
            'fix_per_block' => $value,
            'fix_total' => $value / $totalBlocks,
            'fix_per_student' => $value * $payers,
            default => 0.0,
        };
        // Теория: для percent — прайс без скидок по оплатившим; фикс — как факт.
        $teacherTheor = $isPercent ? ($price * $payers * $value / 100) : $teacherFact;

        // ── Эквайринг по фактическому способу оплаты ─────────────────────────
        $methods = Payment::whereIn('id', array_column($payerLines, 'payment_id'))
            ->pluck('payment_method', 'id');
        $cardRev = 0.0;
        $sbpRev = 0.0;
        $unknownRev = 0.0;
        foreach ($payerLines as $l) {
            $m = $methods[$l['payment_id']] ?? null;
            $share = (float) $l['share'];
            match ($m) {
                'card' => $cardRev += $share,
                'sbp' => $sbpRev += $share,
                default => $unknownRev += $share,
            };
        }
        $acqCardPct = (float) config('economics.acquiring.card', 2.9);
        $acqSbpPct = (float) config('economics.acquiring.sbp', 0.4);
        $acqKnown = $cardRev * $acqCardPct / 100 + $sbpRev * $acqSbpPct / 100;
        $acqUnknownMin = $unknownRev * $acqSbpPct / 100; // всё СБП → минимум комиссии
        $acqUnknownMax = $unknownRev * $acqCardPct / 100; // всё карта → максимум

        // ── Налог УСН ────────────────────────────────────────────────────────
        $taxPct = (float) config('economics.usn_income_pct', 6.0);
        $tax = $revenue * $taxPct / 100;

        // ── CAC: авто из рекламы (Директ + ВК Таргет = DirectAdSpend) за окно блока ──
        $cacAuto = null;
        $cacBudgetWindow = 0.0;
        if ($block && $block->starts_at && $block->ends_at) {
            $spends = DirectAdSpend::where('starts_at', '<=', $block->ends_at)
                ->where('ends_at', '>=', $block->starts_at)->get();
            foreach ($spends as $s) {
                $cacBudgetWindow += $s->budgetForWindow($block->starts_at, $block->ends_at);
            }
            $cacAuto = $payers > 0 ? $cacBudgetWindow / $payers : 0.0;
        }
        $cacPerStudent = $cacOverride ?? ($cacAuto ?? 0.0);
        $cacCost = $cacPerStudent * $payers;

        // ── Прибыль ИП за блок (вилка только на нераспознанном эквайринге) ────
        $knownCosts = $teacherFact + $tax + $cacCost + $acqKnown;
        $profitMax = $revenue - $knownCosts - $acqUnknownMin; // лучший (всё СБП)
        $profitMin = $revenue - $knownCosts - $acqUnknownMax; // худший (всё карта)
        $hasUnknownMethod = $unknownRev > 0.005;

        $L = $lessons;

        return [
            'found' => true,
            'course_title' => (string) $course->title,
            'block_number' => $blockNumber,
            'block_has_dates' => (bool) ($block && $block->starts_at && $block->ends_at),
            'price' => Money::round($price),
            'lessons' => $L,
            'payers' => $payers,
            'free_students' => $freeStudents,
            'revenue' => Money::round($revenue),

            'teacher_name' => $teacher->name ?? null,
            'salary_type' => $type,
            'salary_value' => $value,
            'is_percent' => $isPercent,

            // Ученик — за урок.
            'student_per_lesson' => Money::round($price / $L),

            // Преподаватель — за урок.
            'teacher_block_fact' => Money::round($teacherFact),
            'teacher_block_theor' => Money::round($teacherTheor),
            'teacher_per_lesson_fact' => Money::round($teacherFact / $L),
            'teacher_per_lesson_theor' => Money::round($teacherTheor / $L),

            // Затраты блока.
            'acquiring_known' => Money::round($acqKnown),
            'acquiring_unknown_min' => Money::round($acqUnknownMin),
            'acquiring_unknown_max' => Money::round($acqUnknownMax),
            'has_unknown_method' => $hasUnknownMethod,
            'rev_card' => Money::round($cardRev),
            'rev_sbp' => Money::round($sbpRev),
            'rev_unknown' => Money::round($unknownRev),
            'tax' => Money::round($tax),
            'tax_pct' => $taxPct,
            'cac_per_student' => Money::round($cacPerStudent),
            'cac_auto' => $cacAuto === null ? null : Money::round($cacAuto),
            'cac_total' => Money::round($cacCost),
            'cac_budget_window' => Money::round($cacBudgetWindow),

            // Прибыль ИП — за блок и за урок.
            'profit_block_min' => Money::round($profitMin),
            'profit_block_max' => Money::round($profitMax),
            'profit_per_lesson_min' => Money::round($profitMin / $L),
            'profit_per_lesson_max' => Money::round($profitMax / $L),
        ];
    }
}
