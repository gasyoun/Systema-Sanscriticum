<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SalaryScheme;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\FinanceSnapshot;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Teacher;
use App\Services\Payroll\PayrollRateCalculator;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * H4520 — месячный прогон выплат по ЗАВЕРШЁННЫМ БЛОКАМ (read-only).
 *
 * Читает и печатает; НИ ОДНОЙ записи в payments / teacher_payouts / users /
 * finance_snapshots (отпечатки до/после — в команде). Ядро расчёта —
 * переиспользование, не дублирование:
 *   - завершённый блок: CourseBlock.ends_at в окне (since, on]; пустой ends_at →
 *     фолбэк на даты занятий (max lesson_date блока ≤ on) со строкой ⚠;
 *   - база блока: TeacherSalaryService::blockGroupRevenueDetail() — доли реальных
 *     платежей ЛЮБЫМИ датами прихода, возвраты вычтены, уже выплаченные доли
 *     отсечены через paidShareKeys (opts teacher_id);
 *   - «на руки»: PayrollRateCalculator::netFor() — (база × bank_slice%) × ставка(t),
 *     таймлайн config/teacher_rates.php;
 *   - вычеты: прямые оплаты на счёт преподавателя СВОИХ курсов (номинал, как
 *     blockPayoutTotal directOffset), авансы (outstandingAdvanceItems);
 *   - ловушка pass-through: платежи, где received_by_teacher_id = T, но курс НЕ
 *     преподавателя — НЕ вычет; отдельный список «посреднические»;
 *   - предоплаты задним числом (платёж ≥ 2 блоков, все завершены ДО прихода
 *     денег, кейс #14142 Соловьева) — НЕ в текущей выплате; помесячная рента по
 *     рулингу MG 26-08, старт признания — открытый @DECIDE.
 *
 * Якорная строка (base × slice%) × rate% = netFor payable + registry deductions:
 * фикс-регистр (Новикова и т.п.) в прогонах 25-08/26-08 НЕ вычитался, поэтому
 * «к выплате» его не вычитает, а только ПОКАЗЫВАЕТ (строка + ⚠).
 */
final class PayoutRunService
{
    public function __construct(
        private readonly TeacherSalaryService $salaries,
        private readonly PayrollRateCalculator $rates,
    ) {}

    /**
     * Строгий расчёт по одному преподавателю. $since = null → авто-отсечка
     * (max paid_at teacher_payouts, иначе последняя «Расход»-запись по курсам);
     * не найдено и не задано → ['error' => ...].
     *
     * @return array<string, mixed>
     */
    public function runForTeacher(Teacher $teacher, ?Carbon $on = null, ?Carbon $since = null): array
    {
        $on ??= Carbon::today()->startOfDay();
        $slug = $this->rates->matchName((string) $teacher->name);
        $warnings = [];

        if ($since === null) {
            $since = $this->defaultSince($teacher);
        }
        if ($since === null) {
            return [
                'teacher_id' => (int) $teacher->id,
                'name' => (string) $teacher->name,
                'error' => 'отсечка не определена: ни одной выплаты/«Расход»-записи — задайте --since=YYYY-MM-DD',
            ];
        }

        if ($slug === null) {
            $warnings[] = '⚠ нет слота в config/teacher_rates.php по ФИО — расчёт по таймлайну невозможен';
        }

        [$percentCourses, $skipped] = $this->percentCourses($teacher);
        foreach ($skipped as $course) {
            $warnings[] = sprintf('⚠ курс «%s»: схема %s — не процентная, в прогон не входит',
                $course->title, $course->salary_type ?: '?');
        }

        // Даты завершения блоков: ends_at, фолбэк занятия, «неопределимо» → ⚠.
        $completions = []; // "course:block" => ['at' => ?Carbon, 'estimated' => bool, 'course' => Course, 'number' => int]
        foreach ($percentCourses as $course) {
            foreach ($this->completionsFor($course, $on) as $number => $info) {
                if ($info['at'] === null) {
                    $warnings[] = sprintf('⚠ %s блок %d: нет ends_at и дат занятий — завершение неопределимо, блок не вошёл (не молчаливый ноль)',
                        $course->title, $number);

                    continue;
                }
                if ($info['estimated']) {
                    $warnings[] = sprintf('⚠ %s блок %d: ends_at пуст — завершение оценено по занятиям (%s)',
                        $course->title, $number, $info['at']->toDateString());
                }
                $completions[$course->id.':'.$number] = $info + ['course' => $course, 'number' => $number];
            }
        }

        // 1. Блоки, завершённые В ОКНЕ (since, on].
        $windowBlocks = [];
        $baseRub = 0.0;
        foreach ($completions as $info) {
            if (! $info['at']->gt($since->copy()->startOfDay()) || $info['at']->gt($on->copy()->endOfDay())) {
                continue;
            }
            $detail = $this->salaries->blockGroupRevenueDetail(
                (int) $info['course']->id, (int) $info['number'], null, ['teacher_id' => (int) $teacher->id]);
            $windowBlocks[] = [
                'course_id' => (int) $info['course']->id,
                'course_title' => (string) $info['course']->title,
                'block_number' => (int) $info['number'],
                'completed_on' => $info['at']->toDateString(),
                'ends_at_estimated' => (bool) $info['estimated'],
                'base_rub' => $detail['total'],
                'paid_students' => count(collect($detail['lines'])->where('is_return', false)->pluck('user_id')->unique()),
                'lines' => $detail['lines'],
            ];
            $baseRub += (float) $detail['total'];
        }

        // 2. Перерасчёт: завершённые ДО отсечки блоки с ещё не выплаченными долями
        //    (поздние оплаты, «64-й блок: 1 платный (перерасчёт)»). Доли уже
        //    выплаченные отсечены внутри blockGroupRevenueDetail (paidShareKeys).
        $priorBlocks = [];
        $priorRub = 0.0;
        $rentByPayment = []; // payment_id => rent row
        foreach ($completions as $key => $info) {
            if ($info['at']->gt($since->copy()->endOfDay())) {
                continue; // окно или будущее — не перерасчёт
            }
            $detail = $this->salaries->blockGroupRevenueDetail(
                (int) $info['course']->id, (int) $info['number'], null, ['teacher_id' => (int) $teacher->id]);
            if ($detail['lines'] === []) {
                continue;
            }
            $courseBlockNumbers = CourseBlock::query()
                ->where('course_id', $info['course']->id)
                ->orderBy('number')
                ->pluck('number')
                ->map(fn ($n) => (int) $n)
                ->all();
            $kept = [];
            foreach ($detail['lines'] as $line) {
                if (! empty($line['is_return'])) {
                    $kept[] = $line;

                    continue;
                }
                $payment = Payment::query()->find((int) $line['payment_id']);
                if ($payment !== null
                    && $this->isRetroactivePrepayment($payment, $courseBlockNumbers, $completions, (int) $info['course']->id)) {
                    $covered = $this->coveredBlocksOf($payment, $courseBlockNumbers);
                    $rent = $rentByPayment[(int) $payment->id] ?? [
                        'payment_id' => (int) $payment->id,
                        'student' => $line['user_name'],
                        'course_id' => (int) $info['course']->id,
                        'course_title' => (string) $info['course']->title,
                        'amount_rub' => Money::round((float) $payment->amount),
                        'covered_blocks' => count($covered),
                    ];
                    $rent['base_share_per_block_rub'] = Money::round((float) $payment->amount / max(1, $rent['covered_blocks']));
                    $rentByPayment[(int) $payment->id] = $rent;

                    continue;
                }
                $kept[] = $line;
            }
            if ($kept === []) {
                continue;
            }
            $keptTotal = Money::round(array_sum(array_map(fn (array $l): float => (float) $l['share'], $kept)));
            $priorBlocks[] = [
                'course_title' => (string) $info['course']->title,
                'block_number' => (int) $info['number'],
                'completed_on' => $info['at']->toDateString(),
                'base_rub' => $keptTotal,
                'lines' => $kept,
            ];
            $priorRub += $keptTotal;
        }

        $baseTotal = Money::round($baseRub + $priorRub);

        // 3. «На руки» по таймлайну ставок.
        $fx = $this->fxEur();
        $lane = $slug !== null ? $this->lane($slug) : 'RUB';
        $result = null;
        $period = null;
        if ($slug !== null) {
            $result = $this->rates->netFor($slug, $on, [
                'receipts_rub' => [$baseTotal],
                'fx_rate' => $lane === 'EUR' ? $fx['rate'] : null,
            ]);
            $period = $result['period'];
        }

        // Якорная строка (база × срез%) × ставка% — без фикс-регистра.
        $accruedFormula = $result !== null
            ? Money::round((float) $result['payable_rub'] + (float) $result['deductions_rub'])
            : 0.0;

        // 4. Прямые оплаты на личный счёт (свои курсы) — вычет по номиналу.
        $direct = $this->windowDirectReceipts($teacher, $since, $on);

        // 5. Посреднические (pass-through): пришли НА счёт T, но курс чужой.
        $passThrough = $this->passThroughReceipts($teacher, $since, $on);

        // 6. Незакрытые авансы.
        $advances = $this->salaries->outstandingAdvanceItems($teacher);
        $advancesTotal = Money::round(array_sum(array_column($advances, 'remaining')));

        // Рента: учительская доля помесячно (доля блока × срез% × ставка% на дату прогона).
        $rentRows = [];
        if ($rentByPayment !== []) {
            $valuePct = is_array($period) && isset($period['value_pct']) ? (float) $period['value_pct'] : null;
            if ($valuePct === null) {
                $warnings[] = '⚠ предоплата(-ы) задним числом найдены, но ставка на дату прогона не процентная — рента не рассчитана';
            } else {
                $slice = (float) ($period['bank_slice_pct'] ?? 100.0) / 100.0;
                foreach ($rentByPayment as $rent) {
                    $rent['teacher_rent_per_month_rub'] = Money::round($rent['base_share_per_block_rub'] * $slice * $valuePct / 100.0);
                    $rent['teacher_rent_total_rub'] = Money::round($rent['teacher_rent_per_month_rub'] * $rent['covered_blocks']);
                    $rent['note'] = 'предоплата задним числом — НЕ в текущей выплате; помесячно по рулингу MG 26-08; старт признания (задним числом/вперёд) — @DECIDE';
                    $rentRows[] = $rent;
                }
            }
        }

        // 7. К выплате: якорная формула − прямые (номинал своей валюты) − авансы.
        //    Фикс-регистр НЕ вычитается (в прогонах 25-08/26-08 не вычитался) — только показывается.
        $payableRub = $accruedFormula - $advancesTotal;
        $directRubTotal = 0.0;
        $directForeignTotal = 0.0;
        if ((float) $direct['total'] > 0) {
            if (($direct['currency'] ?? null) === 'EUR') {
                $directForeignTotal = (float) $direct['total'];
            } else {
                $directRubTotal = (float) $direct['total'];
                $payableRub -= $directRubTotal;
            }
        }
        $payableRub = Money::round($payableRub);
        $payableEur = null;
        if ($lane === 'EUR' && $fx['rate'] > 0) {
            $payableEur = Money::round($accruedFormula / $fx['rate'] - $directForeignTotal);
        }

        return [
            'teacher_id' => (int) $teacher->id,
            'name' => (string) $teacher->name,
            'slug' => $slug,
            'lane' => $lane,
            'window' => ['since' => $since->toDateString(), 'on' => $on->toDateString()],
            'blocks' => $windowBlocks,
            'prior_blocks' => $priorBlocks,
            'base_rub' => Money::round($baseRub),
            'prior_rub' => Money::round($priorRub),
            'base_total_rub' => $baseTotal,
            'formula_note' => $result['notes'] ?? ['⚠ нет слота в config/teacher_rates.php'],
            'accrued_formula_rub' => $accruedFormula,
            'rate_period' => $period,
            'registry_deductions_rub' => $result !== null ? Money::round((float) $result['deductions_rub']) : 0.0,
            'registry_deductions_detail' => $this->registryDetail($result),
            'registry_note' => 'фикс-регистр (Новикова и т.п.) показывается, но НЕ вычитается: в прогонах 25-08/26-08 не вычитался — подтвердите актуальность перед боевой выплатой',
            'direct_receipts' => $direct + ['rub_offset' => $directRubTotal, 'eur_offset' => $directForeignTotal],
            'pass_through' => $passThrough,
            'advances' => $advances,
            'advances_total_rub' => $advancesTotal,
            'prepayment_rent' => $rentRows,
            'payable_rub' => $payableRub,
            'payable_eur' => $payableEur,
            'fx' => $fx + ['note' => 'Xoom/PayPal-курс берётся на момент отправки, не курс дня прогона'],
            'npd_pct' => $result['npd_pct'] ?? null,
            'net_after_npd_rub' => $result['net_after_npd_rub'] ?? null,
            'withholding_reading' => 'одно удержание ×92% (банковский срез): для percent-схем (X×0,92)×r = (X×r)×0,92 — вопрос MG 10-09 «то же или второе удержание?» на цифру percent-прогонов не влияет; двойной срез не применяется',
            'warnings' => $warnings,
        ];
    }

    /** Авто-отсечка: последняя выплата, иначе последняя «Расход»-запись по курсам. */
    public function defaultSince(Teacher $teacher): ?Carbon
    {
        $lastPayout = $teacher->payouts()->max('paid_at');
        if ($lastPayout !== null) {
            return Carbon::parse((string) $lastPayout)->startOfDay();
        }

        $courseIds = $teacher->allTaughtCourses()->modelKeys();
        if ($courseIds !== []) {
            $lastExpense = Payment::query()
                ->whereIn('course_id', $courseIds)
                ->paid()
                ->where('tariff', 'Расход')
                ->max('first_paid_at');
            if ($lastExpense !== null) {
                return Carbon::parse((string) $lastExpense)->startOfDay();
            }
        }

        return null;
    }

    /**
     * Даты завершения блоков курса: ends_at; пустой → фолбэк на даты занятий
     * (max lesson_date блока ≤ on); ни того ни другого → at=null (вызывающий
     * код выдаёт видимый ⚠ — не молчаливый ноль).
     *
     * @return array<int, array{at: ?Carbon, estimated: bool}> номер блока => завершение
     */
    public function completionsFor(Course $course, Carbon $on): array
    {
        $out = [];
        $blocks = CourseBlock::query()
            ->where('course_id', $course->id)
            ->orderBy('number')
            ->get(['number', 'ends_at']);

        foreach ($blocks as $block) {
            if ($block->ends_at !== null) {
                if ($block->ends_at->lte($on->copy()->endOfDay())) {
                    $out[(int) $block->number] = ['at' => $block->ends_at->copy(), 'estimated' => false];
                }

                continue;
            }

            $lastLesson = Lesson::query()
                ->where('course_id', $course->id)
                ->where('block_number', (int) $block->number)
                ->whereNotNull('lesson_date')
                ->where('lesson_date', '<=', $on->toDateString())
                ->max('lesson_date');
            $out[(int) $block->number] = [
                'at' => $lastLesson !== null ? Carbon::parse((string) $lastLesson)->endOfDay() : null,
                'estimated' => true,
            ];
        }

        return $out;
    }

    /**
     * Должники по курсам: участники групп без реальной оплаты, покрывающей
     * завершённый блок («5 платных из 7, не оплатили — X, Y»).
     *
     * @param  list<array<string, mixed>>  $windowBlocks
     * @return list<array<string, mixed>>
     */
    public function debtorsFor(Teacher $teacher, array $windowBlocks): array
    {
        $byCourse = [];
        foreach ($windowBlocks as $b) {
            $byCourse[(int) $b['course_id']]['title'] = (string) $b['course_title'];
            $byCourse[(int) $b['course_id']]['blocks'][(int) $b['block_number']] = $b;
        }

        $out = [];
        foreach ($byCourse as $courseId => $entry) {
            $course = Course::query()->find($courseId);
            if ($course === null) {
                continue;
            }
            $memberIds = [];
            foreach ($course->groups as $group) {
                foreach ($group->users()->get(['users.id', 'users.name']) as $u) {
                    $memberIds[(int) $u->id] = (string) $u->name;
                }
            }
            if ($memberIds === []) {
                continue;
            }

            $blocks = [];
            foreach ($entry['blocks'] as $number => $b) {
                $paidIds = [];
                foreach ($b['lines'] as $line) {
                    if (empty($line['is_return']) && isset($line['user_id'])) {
                        $paidIds[(int) $line['user_id']] = true;
                    }
                }
                $nonPayers = array_values(array_filter(
                    $memberIds,
                    fn (string $name, int $id): bool => ! isset($paidIds[$id]),
                    ARRAY_FILTER_USE_BOTH,
                ));
                $blocks[] = [
                    'block_number' => (int) $number,
                    'group_size' => count($memberIds),
                    'paid' => count($paidIds),
                    'non_payers' => $nonPayers,
                ];
            }
            $out[] = ['course_id' => $courseId, 'course_title' => $entry['title'], 'blocks' => $blocks];
        }

        return $out;
    }

    /** @return array{rate: float, source: string} */
    public function fxEur(): array
    {
        $snap = FinanceSnapshot::latestOfType(FinanceSnapshot::TYPE_FX_EUR_RUB);
        if ($snap !== null) {
            return ['rate' => $snap->majorAmount(), 'source' => 'finance_snapshots @ '.$snap->entered_at?->format('d.m.Y')];
        }

        return ['rate' => (float) config('teacher_rates.canon.fx_eur_rub_fallback', 90.1127), 'source' => 'config_fallback (исторический)'];
    }

    /**
     * Предоплата задним числом (кейс #14142): платёж покрывает ≥ 2 блоков, ВСЕ
     * они завершены ДО прихода денег. Одиночная поздняя оплата прошлого блока —
     * обычный перерасчёт, не предоплата; full-платёж с будущими блоками —
     * обычный дрип (по мере прохождения).
     *
     * @param  array<int, array{at: ?Carbon, estimated: bool}>  $completions
     * @param  list<int>  $blockNumbers
     */
    public function isRetroactivePrepayment(Payment $payment, array $blockNumbers, array $completions, int $courseId): bool
    {
        $covered = $this->coveredBlocksOf($payment, $blockNumbers);
        if (count($covered) < 2 || $payment->created_at === null) {
            return false;
        }
        foreach ($covered as $number) {
            $at = $completions[$courseId.':'.$number]['at'] ?? null;
            if ($at === null || $at->gte($payment->created_at)) {
                return false; // незавершённый или завершённый позже прихода — обычный дрип
            }
        }

        return true;
    }

    /** @return list<int> */
    private function coveredBlocksOf(Payment $payment, array $blockNumbers): array
    {
        return BlockMonthRecognition::coveredBlockNumbers($payment->start_block, $payment->end_block, $blockNumbers);
    }

    /**
     * Процентные курсы преподавателя + пропущенные (не процентные) с ⚠.
     *
     * @return array{0: list<Course>, 1: list<Course>}
     */
    private function percentCourses(Teacher $teacher): array
    {
        $percent = [];
        $skipped = [];
        foreach ($teacher->allTaughtCourses() as $course) {
            if (TeacherSalaryService::isTechnicalCourse($course)) {
                continue;
            }
            $terms = $course->salaryTermsFor((int) $teacher->id);
            if ($terms === null || ! SalaryScheme::isPercentType((string) $terms['type'])) {
                $skipped[] = $course;

                continue;
            }
            $percent[] = $course;
        }

        return [$percent, $skipped];
    }

    /**
     * Прямые оплаты своих курсов в окне (since, on] — из канонического
     * directReceiptsForTeacher (свои курсы по построению), с дневной фильтрацией
     * поверх месячной.
     *
     * @return array{total: float, currency: ?string, lines: list<array<string, mixed>>}
     */
    private function windowDirectReceipts(Teacher $teacher, Carbon $since, Carbon $on): array
    {
        $all = $this->salaries->directReceiptsForTeacher($teacher, $since, $on);
        $lines = [];
        $total = 0.0;
        foreach ($all['lines'] as $line) {
            $at = $this->parseDotDate($line['date'] ?? null);
            if ($at === null || ! $at->gt($since->copy()->startOfDay()) || $at->gt($on->copy()->endOfDay())) {
                continue;
            }
            $lines[] = $line;
            if (! $line['mismatch']) {
                $total += (float) $line['amount'];
            }
        }

        return ['total' => Money::round($total), 'currency' => $all['currency'], 'lines' => $lines];
    }

    /**
     * Посреднические: получены НА счёт преподавателя, но курс ведёт другой —
     * НЕ вычет, отдельный список для ручной сверки (кейс «763 € = 200 переводы
     * учеников + 563 наш PayPal», 24-03-2026).
     *
     * @return array{total_rub: float, lines: list<array<string, mixed>>}
     */
    private function passThroughReceipts(Teacher $teacher, Carbon $since, Carbon $on): array
    {
        $ownCourseIds = $teacher->allTaughtCourses()->modelKeys();

        $query = Payment::query()
            ->with(['user:id,name', 'course:id,title,teacher_id'])
            ->paid()
            ->real()
            ->teacherReceived()
            ->where('received_by_teacher_id', (int) $teacher->id)
            ->where(function ($q) use ($since, $on) {
                $q->where(fn ($w) => $w->whereNotNull('first_paid_at')
                    ->where('first_paid_at', '>', $since->copy()->startOfDay())
                    ->where('first_paid_at', '<=', $on->copy()->endOfDay()))
                    ->orWhere(fn ($w) => $w->whereNull('first_paid_at')
                        ->where('created_at', '>', $since->copy()->startOfDay())
                        ->where('created_at', '<=', $on->copy()->endOfDay()));
            });

        $lines = [];
        $total = 0.0;
        foreach ($query->get() as $p) {
            if (in_array((int) $p->course_id, $ownCourseIds, true)) {
                continue; // свой курс — это вычет (direct_receipts), не посредничество
            }
            $lines[] = [
                'payment_id' => (int) $p->id,
                'student' => $p->user?->name ?? ('#'.$p->user_id),
                'course_title' => (string) ($p->course?->title ?? ('курс #'.$p->course_id)),
                'amount_rub' => Money::round((float) $p->amount),
                'foreign_amount' => $p->foreign_amount !== null ? (float) $p->foreign_amount : null,
                'foreign_currency' => $p->foreign_currency,
                'date' => ($p->first_paid_at ?? $p->created_at)?->format('d.m.Y'),
            ];
            $total += (float) $p->amount;
        }

        return ['total_rub' => Money::round($total), 'lines' => $lines];
    }

    /**
     * Расшифровка применённых фикс-вычетов из notes netFor (строки «− вычет …»).
     *
     * @param  array<string, mixed>|null  $result
     * @return list<string>
     */
    private function registryDetail(?array $result): array
    {
        if ($result === null) {
            return [];
        }

        return array_values(array_filter(
            (array) ($result['notes'] ?? []),
            fn (string $n): bool => str_starts_with($n, '− вычет'),
        ));
    }

    private function lane(string $slug): string
    {
        $rcpt = $this->rates->find($slug);

        return $rcpt !== null ? (string) $rcpt['lane'] : 'RUB';
    }

    private function parseDotDate(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = Carbon::createFromFormat('d.m.Y', $value);

        return $parsed === false ? null : $parsed->startOfDay();
    }
}
