<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FinanceSnapshot;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\PayoutRunService;
use App\Services\TeacherSalaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * H4520 — payout:run: месячный прогон выплат по завершённым блокам.
 *
 * READ ONLY: ни payments, ни teacher_payouts, ни users, ни finance_snapshots —
 * отпечатки до/после входят в вывод и роняют exit-код при расхождении.
 * Форматы: json (машина), marina (текст §3б PAYROLL_LEITAN_MONTHLY_RECON —
 * чат/письмо преподавателю), report (markdown-ведомость: начислено/выплачено/
 * остаток + должники по курсам).
 *
 * Первый боевой прогон — только после бэктест-гейта и ответа MG на вопрос
 * про удержание 8 % (см. withholding_reading в JSON).
 */
class PayoutRunCommand extends Command
{
    protected $signature = 'payout:run
        {--teacher= : id преподавателя или список через запятую (взаимоисключающе с --all)}
        {--all : все преподаватели с процентными курсами}
        {--on= : дата прогона YYYY-MM-DD (default: сегодня)}
        {--since= : отсечка последней выплаты YYYY-MM-DD (default: авто — max paid_at / «Расход»)}
        {--format=json : json,marina,report — можно несколько через запятую}';

    protected $description = 'Read-only месячный прогон выплат по завершённым блокам (json/marina/report)';

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
            ->unique();
        $unknown = $formats->reject(fn (string $f) => in_array($f, ['json', 'marina', 'report'], true));
        if ($unknown->isNotEmpty()) {
            $this->error('неизвестный формат: '.$unknown->implode(',').' (доступны json, marina, report)');

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
                'report' => $this->renderReport($runner, $salaries, $rows, $on),
            });
        }

        if ($moved) {
            $this->error('READ-ONLY НАРУШЕН: отпечатки money-таблиц изменились — прогон недействителен');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Текст по образцу docs/PAYROLL_LEITAN_MONTHLY_RECON_26-08-2026.md §3б.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderMarina(array $rows, Carbon $on): string
    {
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['error'])) {
                $out[] = sprintf('%s: ⚠ %s', $row['name'] ?? ('препод #'.$row['teacher_id']), $row['error']);

                continue;
            }
            $period = $row['rate_period'];
            $slicePct = is_array($period) ? ($period['bank_slice_pct'] ?? null) : null;
            $ratePct = is_array($period) && isset($period['value_pct']) ? rtrim(rtrim((string) $period['value_pct'], '0'), '.') : null;

            // Группировка по курсам: блоки окна + перерасчёт прошлых блоков.
            $byCourse = [];
            foreach ($row['blocks'] as $b) {
                $byCourse[$b['course_title']]['blocks'][] = $b;
            }
            foreach ($row['prior_blocks'] as $b) {
                $byCourse[$b['course_title']]['prior'][] = $b;
            }

            $courseList = array_map(fn (string $t) => '«'.$t.'»', array_keys($byCourse));
            $out[] = sprintf('%s %s по %s:',
                $row['name'],
                implode(' и ', $courseList ?: ['(нет завершённых блоков)']),
                $on->format('d.m.Y'));

            foreach ($byCourse as $courseTitle => $entry) {
                $courseBase = 0.0;
                $blockLines = [];
                foreach ($entry['blocks'] ?? [] as $b) {
                    $courseBase += (float) $b['base_rub'];
                    $blockLines[] = sprintf('%d-й блок: %d платных', $b['block_number'], $b['paid_students']);
                }
                foreach ($entry['prior'] ?? [] as $b) {
                    $courseBase += (float) $b['base_rub'];
                    $blockLines[] = sprintf('%d-й блок: перерасчёт (%s)',
                        $b['block_number'],
                        implode(', ', array_map(fn (array $l) => (string) ($l['user_name'] ?? '#'.$l['payment_id']), $b['lines'])));
                }
                $out[] = sprintf('🔹️ %s:', $courseTitle);
                foreach ($blockLines as $line) {
                    $out[] = '   '.$line;
                }
                if ($ratePct !== null) {
                    $sliced = $slicePct !== null ? $this->fmtRub($courseBase * (float) $slicePct / 100.0) : null;
                    $head = $sliced !== null
                        ? sprintf('(%s х %s%%)', $this->fmtRub($courseBase), rtrim(rtrim((string) $slicePct, '0'), '.'))
                        : sprintf('(%s)', $this->fmtRub($courseBase));
                    $out[] = sprintf('%s х %s%% = %s р.', $head, $ratePct, $this->fmtRub($row['accrued_formula_rub']));
                }
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

            $out[] = sprintf('Всего к оплате: %s р.', number_format((float) $row['payable_rub'], 2, ',', ' '));
            if ($row['payable_eur'] !== null) {
                $out[] = sprintf('Курс ЦБ %s: %s -> %s € (Xoom/PayPal — курс берётся на момент отправки)',
                    $on->format('d.m.Y'), rtrim(rtrim((string) $row['fx']['rate'], '0'), '.'),
                    number_format((float) $row['payable_eur'], 2, ',', ' '));
            }
            if ($row['npd_pct'] !== null) {
                $out[] = sprintf('НПД −%s%% по конфигу: нетто %s р. (отдельный шаг выплаты, не внутри суммы)',
                    rtrim(rtrim((string) $row['npd_pct'], '0'), '.'),
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
     * Markdown-ведомость: начислено/выплачено/остаток + должники по курсам.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderReport(PayoutRunService $runner, TeacherSalaryService $salaries, array $rows, Carbon $on): string
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

            $debtors = $runner->debtorsFor(Teacher::query()->findOrFail($row['teacher_id']), $row['blocks']);
            if ($debtors !== []) {
                $out[] = '### Должники по курсам (участники групп без оплаты блока)';
                $out[] = '';
                foreach ($debtors as $d) {
                    foreach ($d['blocks'] as $b) {
                        $out[] = sprintf('- %s, блок %d: платных %d из %d%s',
                            $d['course_title'], $b['block_number'], $b['paid'], $b['group_size'],
                            $b['non_payers'] !== [] ? ' — не оплатили: '.implode(', ', $b['non_payers']) : '');
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
