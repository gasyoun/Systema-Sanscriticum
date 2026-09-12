<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\FinanceSnapshot;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\PayoutRunService;
use App\Services\TeacherSalaryService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * H4520 — payout:run: месячный прогон выплат по завершённым блокам.
 * H4629 — три режима отчёта (json без изменений):
 *   - marina    — чат-формулы 1-в-1: «{N}-й блок: платных X: (база × 92%) × ставка = сумма»,
 *                 перерасчёты отдельными строками, «К оплате: итог», € для EUR-получателей
 *                 (эталоны 16.02–23.07.2026 — Uprava data/h4597_finansy_leitan_kostina_extract.json);
 *   - detailed  — markdown-ведомость + перерасчёты старых блоков ПОИМЁННО
 *                 (ученик, блок, доля, дата платежа, payment_id) + должники по
 *                 завершённым блокам (debtorsFor наружу; список очищен от
 *                 исторического состава групп: вступивший в группу позже
 *                 завершения блока не должник этого блока);
 *   - payments  — лента всех платежей расчёта по датам: дата, ученик, курс,
 *                 блок, сумма (доля), метод (+ прямые/посреднические/возвраты).
 *
 * READ ONLY: ни payments, ни teacher_payouts, ни users, ни finance_snapshots —
 * отпечатки до/после входят в вывод и роняют exit-код при расхождении.
 */
class PayoutRunCommand extends Command
{
    protected $signature = 'payout:run
        {--teacher= : id преподавателя или список через запятую (взаимоисключающе с --all)}
        {--all : все преподаватели с процентными курсами}
        {--on= : дата прогона YYYY-MM-DD (default: сегодня)}
        {--since= : отсечка последней выплаты YYYY-MM-DD (default: авто — max paid_at / «Расход»)}
        {--format=json : json,marina,detailed,payments — можно несколько через запятую; report = прежнее имя detailed}';

    protected $description = 'Read-only месячный прогон выплат по завершённым блокам (json/marina/detailed/payments)';

    public function handle(PayoutRunService $runner, TeacherSalaryService $salaries): int
    {
        $teacherArg = $this->option('teacher');
        $all = (bool) $this->option('all');
        if (($teacherArg === null) === ! $all) {
            $this->error('укажите --teacher=<id[,id]> ИЛИ --all (ровно один из них)');

            return self::FAILURE;
        }

        $on = $this->option('on') !== null && $this->option('on') !== ''
            ? Carbon::parse((string) $this->option('on'))->startOfDay()
            : Carbon::today()->startOfDay();
        $since = $this->option('since') !== null && $this->option('since') !== ''
            ? Carbon::parse((string) $this->option('since'))->startOfDay()
            : null;

        $formats = collect(explode(',', (string) $this->option('format')))
            ->map(fn (string $f) => trim(strtolower($f)))
            ->filter()
            ->unique()
            ->map(fn (string $f) => $f === 'report' ? 'detailed' : $f);
        $unknown = $formats->reject(fn (string $f) => in_array($f, ['json', 'marina', 'detailed', 'payments'], true));
        if ($unknown->isNotEmpty()) {
            $this->error('неизвестный формат: '.$unknown->implode(',').' (доступны json, marina, detailed, payments)');

            return self::FAILURE;
        }

        $teacherIds = $all
            ? Teacher::query()->orderBy('id')->pluck('id')->all()
            : collect(explode(',', (string) $teacherArg))
                ->map(fn (string $s) => (int) trim($s))
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values()
                ->all();

        $before = $this->fingerprint();

        $rows = [];
        foreach ($teacherIds as $tid) {
            $teacher = Teacher::query()->find($tid);
            if ($teacher === null) {
                $rows[] = ['teacher_id' => $tid, 'error' => 'преподаватель не найден'];

                continue;
            }
            $row = $runner->runForTeacher($teacher, $on, $since);
            if ($all && ! isset($row['error'])
                && (float) ($row['base_total_rub'] ?? 0) === 0.0
                && ($row['blocks'] ?? []) === [] && ($row['warnings'] ?? []) === []) {
                continue; // --all: преподавателей без процентных блоков в окне не печатаем
            }
            $rows[] = $row;
        }

        $after = $this->fingerprint();
        $moved = $before !== $after;

        foreach ($formats as $format) {
            $this->line(match ($format) {
                'json' => json_encode([
                    'on' => $on->toDateString(),
                    'since' => $since?->toDateString(),
                    'teachers' => $rows,
                    'read_only' => ['before' => $before, 'after' => $after, 'moved' => $moved],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                'marina' => $this->renderMarina($rows, $on),
                'detailed' => $this->renderDetailed($runner, $salaries, $rows, $on),
                'payments' => $this->renderPayments($rows),
            });
        }

        if ($moved) {
            $this->error('READ-ONLY НАРУШЕН: отпечатки money-таблиц изменились — прогон недействителен');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Чат-формулы 1-в-1 по эталонам Марины (16.02–23.07.2026):
     *   «{N}-й блок: платных X: (база × 92%) × ставка = срез × ставка = сумма р.»,
     * перерасчёты — отдельными строками, итог «К оплате: X € / Y руб.»,
     * €-конверсия только для EUR-получателей (lane = EUR).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderMarina(array $rows, Carbon $on): string
    {
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['error'])) {
                $out[] = sprintf('%s: ⚠ %s', $row['name'] ?? ('препод #'.$row['teacher_id']), $row['error']);
                $out[] = '';

                continue;
            }
            $period = $row['rate_period'];
            $slicePct = is_array($period) ? ($period['bank_slice_pct'] ?? null) : null;
            $ratePct = is_array($period) && isset($period['value_pct']) ? $this->pctTxt((float) $period['value_pct']) : null;
            $isEur = $row['lane'] === 'EUR';
            $fxRate = (float) ($row['fx']['rate'] ?? 0);

            // Заголовок в духе «Лейтан 60-й блок Синтаксиса и 6-й блок Васиштхи по 21.03.26:».
            $headParts = array_map(
                fn (array $b): string => sprintf('%d-й блок %s', $b['block_number'], $b['course_title']),
                $row['blocks'],
            );

            // Группировка по курсам: блоки окна + перерасчёт прошлых блоков.
            $byCourse = [];
            foreach ($row['blocks'] as $b) {
                $byCourse[$b['course_title']]['blocks'][] = $b;
            }
            foreach ($row['prior_blocks'] as $b) {
                $byCourse[$b['course_title']]['prior'][] = $b;
            }

            $out[] = sprintf('%s %s по %s:',
                $row['name'],
                implode(' и ', $headParts ?: ['(нет завершённых блоков)']),
                $on->format('d.m.Y'));

            $courseEurParts = [];
            foreach ($byCourse as $courseTitle => $entry) {
                $out[] = '🔹️ '.$courseTitle.':';
                $parts = [];
                $courseRub = 0.0;
                $lines = [];

                foreach ($entry['blocks'] ?? [] as $b) {
                    [$line, $sum] = $this->marinaBlockLine(
                        sprintf('%d-й блок: платных %d', $b['block_number'], $b['paid_students']),
                        (float) $b['base_rub'], $slicePct, $ratePct);
                    $lines[] = $line;
                    $parts[] = $this->fmtPlain($sum);
                    $courseRub = Money::round($courseRub + $sum);
                }
                foreach ($entry['prior'] ?? [] as $b) {
                    $paid = count(array_filter($b['lines'], fn (array $l): bool => empty($l['is_return'])));
                    [$line, $sum] = $this->marinaBlockLine(
                        sprintf('перерасчёт за %d-й блок: платных %d', $b['block_number'], $paid),
                        (float) $b['base_rub'], $slicePct, $ratePct);
                    $lines[] = $line;
                    $parts[] = $this->fmtPlain($sum);
                    $courseRub = Money::round($courseRub + $sum);
                }

                foreach ($lines as $i => $line) {
                    if ($isEur && $fxRate > 0 && count($lines) === 1) {
                        $line .= sprintf(' = %s €', $this->fmtPlain(Money::round($courseRub / $fxRate)));
                    }
                    $out[] = '   '.$line;
                }
                if (count($lines) > 1) {
                    $courseLine = sprintf('   итого: %s = %s р.', implode(' + ', $parts), $this->fmtPlain($courseRub));
                    if ($isEur && $fxRate > 0) {
                        $courseEurParts[] = $this->fmtPlain(Money::round($courseRub / $fxRate));
                        $courseLine .= sprintf(' = %s €', $courseEurParts[count($courseEurParts) - 1]);
                    }
                    $out[] = $courseLine;
                } elseif ($isEur && $fxRate > 0 && $lines !== []) {
                    $courseEurParts[] = $this->fmtPlain(Money::round($courseRub / $fxRate));
                }
            }

            if ($isEur && $fxRate > 0 && count($courseEurParts) > 1) {
                $out[] = sprintf('Всего: %s = %s евро',
                    implode(' + ', $courseEurParts),
                    $this->fmtPlain(Money::round((float) $row['accrued_formula_rub'] / $fxRate)));
            }

            if ((float) $row['direct_receipts']['total'] > 0) {
                $out[] = sprintf('🔹️ Прямых оплат на счет %s: %s %s (вычтено по номиналу)',
                    $row['name'], number_format((float) $row['direct_receipts']['total'], 2, ',', ' '), $row['direct_receipts']['currency'] ?? '?');
            } else {
                $out[] = sprintf('🔹️ Прямых оплат на счет %s после %s не было.',
                    $row['name'], Carbon::parse((string) $row['window']['since'])->format('d.m'));
            }

            if (($row['pass_through']['lines'] ?? []) !== []) {
                $out[] = sprintf('🔹️ Посреднические (ручная сверка, НЕ вычет): %s — на %s р.',
                    implode(', ', array_map(fn (array $l) => sprintf('%s (%s)', $l['student'], $l['course_title']), $row['pass_through']['lines'])),
                    number_format((float) $row['pass_through']['total_rub'], 2, ',', ' '));
            }

            foreach ($row['prepayment_rent'] as $rent) {
                $out[] = sprintf('🔹️ Рента предоплаты #%d (%s): %s р./мес × %d мес — в текущей выплате НЕ участвует.',
                    $rent['payment_id'], $rent['student'],
                    number_format((float) $rent['teacher_rent_per_month_rub'], 2, ',', ' '), $rent['covered_blocks']);
            }

            if ((float) $row['advances_total_rub'] > 0) {
                $out[] = sprintf('🔹️ Незакрытые авансы: −%s р.', number_format((float) $row['advances_total_rub'], 2, ',', ' '));
            }

            $eurTail = $isEur && $row['payable_eur'] !== null
                ? sprintf(' или %s €', $this->fmtPlain((float) $row['payable_eur']))
                : '';
            $out[] = sprintf('Итого начислено: %s руб.%s', $this->fmtRub((float) $row['accrued_formula_rub']), $eurTail);

            if ($isEur && $row['payable_eur'] !== null) {
                $out[] = sprintf('К оплате: %s € / %s руб.',
                    $this->fmtRub((float) $row['payable_eur']), $this->fmtRub((float) $row['payable_rub']));
            } else {
                $out[] = sprintf('К оплате: %s руб.', $this->fmtRub((float) $row['payable_rub']));
            }
            if ($row['npd_pct'] !== null) {
                $out[] = sprintf('НПД −%s%% по конфигу: нетто %s р. (отдельный шаг выплаты, не внутри суммы)',
                    $this->pctTxt((float) $row['npd_pct']),
                    number_format((float) $row['net_after_npd_rub'], 2, ',', ' '));
            }
            foreach ($row['warnings'] as $warning) {
                $out[] = '⚠ '.$warning;
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * Одна формула-строка Марины: «{кого}: (база × срез%) × ставка% = срез × ставка% = сумма р.».
     *
     * @return array{0: string, 1: float}
     */
    private function marinaBlockLine(string $who, float $base, ?float $slicePct, ?string $ratePct): array
    {
        if ($slicePct === null || $ratePct === null || (float) $ratePct === 0.0) {
            return [sprintf('%s: база %s р. (нет слота в config/teacher_rates.php — формула недоступна)', $who, $this->fmtPlain($base)), 0.0];
        }
        $sliced = Money::round($base * (float) $slicePct / 100.0);
        $sum = Money::round($sliced * (float) $ratePct / 100.0);
        $sliceTxt = $this->pctTxt((float) $slicePct);

        return [
            sprintf('%s: (%s × %s%%) × %s%% = %s × %s%% = %s р.',
                $who, $this->fmtPlain($base), $sliceTxt, $ratePct, $this->fmtPlain($sliced), $ratePct, $this->fmtPlain($sum)),
            $sum,
        ];
    }

    /**
     * Markdown-ведомость: начислено/перерасчёты поимённо/выплачено/остаток +
     * должники по завершённым блокам (очищенные от исторического состава групп).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderDetailed(PayoutRunService $runner, TeacherSalaryService $salaries, array $rows, Carbon $on): string
    {
        $summary = $salaries->summaryForAll($on->format('Y-m'));
        $byId = [];
        foreach ($summary as $s) {
            $byId[(int) $s['teacher_id']] = $s;
        }

        $out = ['# Ведомость выплат по завершённым блокам — '.$on->format('d.m.Y'), ''];
        foreach ($rows as $row) {
            $out[] = '## '.($row['name'] ?? ('препод #'.$row['teacher_id']));
            $out[] = '';
            if (isset($row['error'])) {
                $out[] = '⚠ '.$row['error'];
                $out[] = '';

                continue;
            }
            $out[] = sprintf('Окно: ( %s ; %s ] · слот: %s · линия: %s',
                $row['window']['since'], $row['window']['on'], $row['slug'] ?? '—', $row['lane']);
            $out[] = '';

            $out[] = '### Начислено (завершённые блоки окна)';
            $out[] = '';
            $out[] = '| Курс | Блок | Завершён | Платных | База, ₽ |';
            $out[] = '|---|---|---|---|---|';
            foreach ($row['blocks'] as $b) {
                $out[] = sprintf('| %s | %d | %s%s | %d | %s |',
                    $b['course_title'], $b['block_number'], $b['completed_on'],
                    $b['ends_at_estimated'] ? ' (оценено по занятиям)' : '', $b['paid_students'],
                    number_format((float) $b['base_rub'], 2, ',', ' '));
            }
            foreach ($row['prior_blocks'] as $b) {
                $out[] = sprintf('| %s | %d (перерасчёт) | %s | — | %s |',
                    $b['course_title'], $b['block_number'], $b['completed_on'],
                    number_format((float) $b['base_rub'], 2, ',', ' '));
            }
            $out[] = sprintf('| **База итого** | | | | **%s** |',
                number_format((float) $row['base_total_rub'], 2, ',', ' '));
            $out[] = '';

            // Перерасчёты старых блоков ПОИМЁННО (H4629 режим 2).
            if ($row['prior_blocks'] !== []) {
                $out[] = '### Перерасчёты старых блоков (поимённо)';
                $out[] = '';
                $out[] = '| Ученик | Курс | Блок | Доля, ₽ | Платёж от | payment_id |';
                $out[] = '|---|---|---|---|---|---|';
                foreach ($row['prior_blocks'] as $b) {
                    foreach ($b['lines'] as $l) {
                        $name = (($l['is_return'] ?? false) ? '(возврат) ' : '').(string) ($l['user_name'] ?? ('#'.$l['user_id']));
                        $out[] = sprintf('| %s | %s | %d | %s | %s | %d |',
                            $name,
                            $b['course_title'],
                            (int) $b['block_number'],
                            number_format((float) $l['share'], 2, ',', ' '),
                            $l['created_at'] ?? '—',
                            (int) $l['payment_id']);
                    }
                }
                $out[] = '';
            }

            $out[] = '### Вычеты и пометки';
            $out[] = '';
            if ((float) $row['registry_deductions_rub'] > 0) {
                foreach ($row['registry_deductions_detail'] as $d) {
                    $out[] = '- фикс-регистр: '.$d.' — **НЕ вычтено автоматически** ('.$row['registry_note'].')';
                }
            }
            foreach ($row['direct_receipts']['lines'] as $l) {
                $out[] = sprintf('- прямая оплата: %s, курс «%s», %s %s от %s — вычтено по номиналу',
                    $l['student'], $l['course_title'], $l['amount'], $l['currency'] ?? '?', $l['date']);
            }
            foreach ($row['advances'] as $a) {
                $out[] = sprintf('- незакрытый аванс: выплата #%d, остаток %s ₽ (от %s)',
                    $a['payout_id'], number_format((float) $a['remaining'], 2, ',', ' '), $a['paid_at'] ?? '?');
            }
            foreach ($row['pass_through']['lines'] as $l) {
                $out[] = sprintf('- посредническая (НЕ вычет, ручная сверка): %s заплатил за «%s» через счёт преподавателя — %s ₽ от %s',
                    $l['student'], $l['course_title'], number_format((float) $l['amount_rub'], 2, ',', ' '), $l['date']);
            }
            foreach ($row['prepayment_rent'] as $rent) {
                $out[] = sprintf('- рента предоплаты #%d (%s, %s ₽ за %d блоков): %s ₽/мес, всего %s ₽ — в текущей выплате не участвует',
                    $rent['payment_id'], $rent['student'], number_format((float) $rent['amount_rub'], 2, ',', ' '),
                    $rent['covered_blocks'], number_format((float) $rent['teacher_rent_per_month_rub'], 2, ',', ' '),
                    number_format((float) $rent['teacher_rent_total_rub'], 2, ',', ' '));
            }
            $out[] = '';

            $s = $byId[$row['teacher_id']] ?? null;
            if ($s !== null) {
                $out[] = '### Взаиморасчёт (всё время, по данным панели)';
                $out[] = '';
                $out[] = sprintf('начислено всё время: %s ₽ · выплачено: %s ₽ · остаток: %s ₽',
                    number_format((float) $s['earned_all_time'], 2, ',', ' '),
                    number_format((float) $s['paid_all_time'], 2, ',', ' '),
                    number_format((float) $s['balance'], 2, ',', ' '));
                $out[] = '';
            }

            $debtors = $this->cleanedDebtors($runner, $row);
            if ($debtors !== []) {
                $out[] = '### Должники по завершённым блокам (список очищен от вступивших в группу позже блока)';
                $out[] = '';
                foreach ($debtors as $d) {
                    foreach ($d['blocks'] as $b) {
                        $out[] = sprintf('- %s, блок %d: платных %d из %d%s',
                            $d['course_title'], $b['block_number'], $b['paid'], $b['group_size'],
                            $b['non_payers'] !== [] ? ' — не оплатили: '.implode(', ', $b['non_payers']) : '');
                        if (($b['joined_later'] ?? []) !== []) {
                            $out[] = sprintf('  - не считаем должниками (вступили в группу после завершения блока): %s',
                                implode(', ', $b['joined_later']));
                        }
                    }
                }
                $out[] = '';
            }

            $out[] = '### Итог';
            $out[] = '';
            $out[] = sprintf('**К выплате: %s р.**%s',
                number_format((float) $row['payable_rub'], 2, ',', ' '),
                $row['payable_eur'] !== null
                    ? sprintf(' ≈ %s € (ЦБ %s по снимку: %s)', number_format((float) $row['payable_eur'], 2, ',', ' '), $on->format('d.m.Y'), $row['fx']['source'])
                    : '');
            foreach ($row['warnings'] as $warning) {
                $out[] = '';
                $out[] = '⚠ '.$warning;
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * debtorsFor наружу + чистка от исторического состава групп: участник,
     * вступивший в группу ПОЗЖЕ завершения блока, — не должник этого блока.
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function cleanedDebtors(PayoutRunService $runner, array $row): array
    {
        $teacher = Teacher::query()->find((int) $row['teacher_id']);
        if ($teacher === null || $row['blocks'] === []) {
            return [];
        }
        $debtors = $runner->debtorsFor($teacher, $row['blocks']);
        if ($debtors === []) {
            return [];
        }

        $completedByBlock = [];
        foreach ($row['blocks'] as $b) {
            $completedByBlock[(int) $b['block_number']] = (string) $b['completed_on'];
        }

        foreach ($debtors as &$d) {
            $course = Course::query()->find((int) $d['course_id']);
            if ($course === null) {
                continue;
            }
            $groupIds = $course->groups()->pluck('groups.id')->all();
            $joinedAt = []; // user_id => min created_at по всем группам курса
            if ($groupIds !== []) {
                foreach (DB::table('group_user')->whereIn('group_id', $groupIds)->get(['user_id', 'created_at']) as $p) {
                    $uid = (int) $p->user_id;
                    if (! isset($joinedAt[$uid]) || (string) $p->created_at < $joinedAt[$uid]) {
                        $joinedAt[$uid] = (string) $p->created_at;
                    }
                }
            }
            $idByName = User::query()
                ->whereIn('id', array_keys($joinedAt))
                ->pluck('id', 'name');

            foreach ($d['blocks'] as &$b) {
                $b['joined_later'] = [];
                $completed = $completedByBlock[(int) $b['block_number']] ?? null;
                if ($completed === null) {
                    continue;
                }
                $completedAt = Carbon::parse($completed)->endOfDay();
                $kept = [];
                foreach ($b['non_payers'] as $name) {
                    $uid = $idByName->get((string) $name);
                    $joined = $uid !== null ? ($joinedAt[(int) $uid] ?? null) : null;
                    if ($joined !== null && Carbon::parse($joined)->startOfDay()->gt($completedAt)) {
                        $b['joined_later'][] = (string) $name;
                    } else {
                        $kept[] = (string) $name;
                    }
                }
                $b['non_payers'] = $kept;
            }
            unset($b);
        }
        unset($d);

        return $debtors;
    }

    /**
     * Лента всех платежей расчёта по датам (H4629 режим 3): доли блоков и
     * перерасчётов (сумма = доля в базе), прямые оплаты (вычет),
     * посреднические (не вычет), возвраты; дата/метод обогащаются из payments.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderPayments(array $rows): string
    {
        $out = ['# Лента платежей расчёта', ''];
        foreach ($rows as $row) {
            $out[] = '## '.($row['name'] ?? ('препод #'.$row['teacher_id']));
            $out[] = '';
            if (isset($row['error'])) {
                $out[] = '⚠ '.$row['error'];
                $out[] = '';

                continue;
            }
            $out[] = sprintf('Окно: ( %s ; %s ]', $row['window']['since'], $row['window']['on']);
            $out[] = '';

            $entries = [];
            $paymentIds = [];
            $collect = function (array $lines, string $courseTitle, int $blockNumber, string $kind) use (&$entries, &$paymentIds): void {
                foreach ($lines as $l) {
                    $entries[] = [
                        'date' => $l['created_at'] ?? null,
                        'student' => (string) ($l['user_name'] ?? ('#'.$l['user_id'])),
                        'course' => $courseTitle,
                        'block' => $blockNumber,
                        'amount' => (float) $l['share'],
                        'is_return' => (bool) ($l['is_return'] ?? false),
                        'payment_id' => (int) $l['payment_id'],
                        'kind' => $kind,
                        'foreign' => null,
                        'method' => null,
                    ];
                    $paymentIds[] = (int) $l['payment_id'];
                }
            };
            foreach ($row['blocks'] as $b) {
                $collect($b['lines'], (string) $b['course_title'], (int) $b['block_number'], 'доля блока');
            }
            foreach ($row['prior_blocks'] as $b) {
                $collect($b['lines'], (string) $b['course_title'], (int) $b['block_number'], 'перерасчёт');
            }
            foreach ($row['direct_receipts']['lines'] as $l) {
                $entries[] = [
                    'date' => $l['date'] ?? null,
                    'student' => (string) $l['student'],
                    'course' => (string) $l['course_title'],
                    'block' => null,
                    'amount' => (float) $l['amount'],
                    'is_return' => false,
                    'payment_id' => null,
                    'kind' => 'прямая на счёт (вычет по номиналу)',
                    'foreign' => isset($l['currency']) ? sprintf('%s %s', rtrim(rtrim(number_format((float) $l['amount'], 2, '.', ''), '0'), '.'), $l['currency']) : null,
                    'method' => null,
                ];
            }
            foreach ($row['pass_through']['lines'] as $l) {
                $entries[] = [
                    'date' => $l['date'] ?? null,
                    'student' => (string) $l['student'],
                    'course' => (string) $l['course_title'],
                    'block' => null,
                    'amount' => (float) $l['amount_rub'],
                    'is_return' => false,
                    'payment_id' => (int) $l['payment_id'],
                    'kind' => 'посредническая (НЕ вычет)',
                    'foreign' => $l['foreign_amount'] !== null
                        ? sprintf('%s %s', rtrim(rtrim(number_format((float) $l['foreign_amount'], 2, '.', ''), '0'), '.'), $l['foreign_currency'] ?? '?')
                        : null,
                    'method' => null,
                ];
                $paymentIds[] = (int) $l['payment_id'];
            }

            // Обогащение: дата выплаты (first_paid_at, fallback created_at) и метод.
            $paidById = Payment::query()
                ->whereIn('id', array_values(array_unique($paymentIds)))
                ->get(['id', 'payment_method', 'first_paid_at', 'created_at'])
                ->keyBy('id');
            foreach ($entries as &$e) {
                if ($e['payment_id'] !== null && isset($paidById[$e['payment_id']])) {
                    $p = $paidById[$e['payment_id']];
                    $e['method'] = $this->methodLabel($p->payment_method);
                    $at = $p->first_paid_at ?? $p->created_at;
                    if ($at !== null) {
                        $e['date'] = $at->format('d.m.Y');
                    }
                }
            }
            unset($e);

            usort($entries, fn (array $a, array $b): int => [$a['date'] ?? '', $a['payment_id'] ?? 0] <=> [$b['date'] ?? '', $b['payment_id'] ?? 0]);

            if ($entries === []) {
                $out[] = '_В расчёте нет платежей._';
                $out[] = '';

                continue;
            }

            $currentDate = null;
            foreach ($entries as $e) {
                if ($e['date'] !== $currentDate) {
                    $currentDate = $e['date'];
                    $out[] = sprintf('**%s**', $currentDate ?? 'дата не определена');
                }
                $courseTxt = '«'.$e['course'].'»'.($e['block'] !== null ? sprintf(', блок %d', $e['block']) : '');
                $idTxt = $e['payment_id'] !== null ? sprintf('#%d', $e['payment_id']) : null;
                $amountTxt = number_format($e['amount'], 2, ',', ' ').' р.'.($e['foreign'] !== null ? ' ('.$e['foreign'].')' : '');
                $methodTxt = $e['method'] ?? null;
                $returnTxt = $e['is_return'] ? 'возврат ('.$e['kind'].')' : $e['kind'];
                $parts = array_filter([$e['student'], $courseTxt, trim($returnTxt.' '.$idTxt), $amountTxt, $methodTxt], fn (?string $p): bool => $p !== null && $p !== '');
                $out[] = '- '.implode(' — ', $parts);
            }
            $out[] = '';
            $out[] = sprintf('Доли расчёта: %s р. · прямые: %s · посреднические: %s р.',
                number_format((float) $row['base_total_rub'], 2, ',', ' '),
                number_format((float) $row['direct_receipts']['total'], 2, ',', ' ').' '.($row['direct_receipts']['currency'] ?? '₽'),
                number_format((float) $row['pass_through']['total_rub'], 2, ',', ' '));
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /** «Карта», «СБП», «Наличные», «Долями»…; null → «не определён». */
    private function methodLabel(?string $method): string
    {
        return match ($method) {
            'card' => 'Карта',
            'sbp' => 'СБП',
            'dolyame' => 'Долями',
            'cash' => 'Наличные',
            null => 'не определён',
            default => (string) $method,
        };
    }

    /** «30» / «92» / «86,85» — проценты и курсы без хвостовых нулей (30 не превращается в 3). */
    private function pctTxt(float $value): string
    {
        $txt = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $txt === '' || $txt === '-0' ? '0' : str_replace('.', ',', $txt);
    }

    /** Формат формул Марины: без разделителей тысяч, без «,00» — 44160 / 29145,60. */
    private function fmtPlain(float $amount): string
    {
        $txt = number_format($amount, 2, ',', '');

        return str_ends_with($txt, ',00') ? substr($txt, 0, -3) : $txt;
    }

    /** 29145,60 — формат сумм как в расчётах Марины (§3б). */
    private function fmtRub(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }

    /** @return array{teacher_payouts: int, payments: int, users: int, finance_snapshots: int} */
    private function fingerprint(): array
    {
        return [
            'teacher_payouts' => TeacherPayout::query()->count(),
            'payments' => Payment::query()->count(),
            'users' => User::query()->count(),
            'finance_snapshots' => FinanceSnapshot::query()->count(),
        ];
    }
}
