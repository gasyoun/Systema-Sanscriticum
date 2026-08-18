<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CourseStreamComparisonReport;
use Illuminate\Console\Command;

/**
 * H3083 — воспроизводимая сверка экрана «Потоки курса» с эталоном.
 *
 * Эталон — таблица §1 обложки плана
 * docs/PLAN_SYSTEMA_COURSE_STREAM_ANALYTICS_2026.md, снятая с прода 18-08-2026.
 * Команда печатает посчитанное рядом с эталоном и выходит НЕНУЛЕВЫМ кодом при
 * расхождении: сверка должна быть повторяемой, а не разовой.
 *
 * Эталон живёт здесь, а не в тесте, потому что проверяется он на боевой базе,
 * где фикстуры недоступны. На пустой базе (тесты, свежий стенд) семьи из
 * эталона просто нет — команда честно говорит об этом и выходит с кодом 0:
 * «нечего сверять» и «сверка не сошлась» — разные события.
 */
class VerifyStreamComparison extends Command
{
    protected $signature = 'report:verify-stream-comparison
        {--family= : Слаг семьи потоков; без него сверяются все семьи, для которых есть эталон}';

    protected $description = 'Сверить отчёт «Потоки курса» с эталоном, снятым с прода';

    /**
     * Эталон: слаг семьи => ожидаемые значения.
     *
     * `buyers` — сколько человек купили ИМЕННО этот блок (то же определение,
     * что у выручки блока). Счёт «имеют доступ» отличается на купивших курс
     * целиком и в эталон §1 не входит — см. шапку CourseStreamComparisonReport.
     *
     * `accrued` — ВАЛОВОЕ начисление (30 % от выручки), как в §1 PLAN. Курс без
     * схемы ЗП даёт 0 — в волне 1 это правильный результат, а не баг (фенс).
     *
     * @var array<string, array<string, mixed>>
     */
    private const EXPECTED = [
        'kasmirskii-sivaizm' => [
            'title' => 'Кашмирский шиваизм',
            'streams' => [
                332 => [
                    'payers' => 51,
                    'revenue' => 461251.0,
                    'blocks' => [1 => 44, 2 => 39, 3 => 36, 4 => 31],
                    'block_revenue' => [1 => 132000.0, 2 => 117000.0, 3 => 108000.0, 4 => 92250.0],
                    'accrued' => 138375.30,
                ],
                375 => [
                    'payers' => 28,
                    'revenue' => 277200.0,
                    'blocks' => [1 => 27, 2 => 21, 3 => 20, 4 => 18],
                    'block_revenue' => [1 => 92550.0, 2 => 65550.0, 3 => 62550.0, 4 => 56550.0],
                    'accrued' => 83160.0,
                ],
                424 => [
                    'payers' => 5,
                    'revenue' => 51000.0,
                    'blocks' => [1 => 5, 2 => 4, 3 => 4, 4 => 4],
                    'block_revenue' => [1 => 15000.0, 2 => 12000.0, 3 => 12000.0, 4 => 12000.0],
                    'accrued' => 0.0,
                ],
            ],
            'crossover' => [[332, 375, 10]],
            'recording' => [424 => ['buyers' => 5, 'also_live' => 4, 'only_recording' => 1]],
        ],
    ];

    public function handle(CourseStreamComparisonReport $report): int
    {
        $families = $this->option('family')
            ? [$this->resolveFamily((string) $this->option('family'))]
            : array_keys(self::EXPECTED);

        $families = array_values(array_filter($families));
        if ($families === []) {
            return self::FAILURE;
        }

        $failures = 0;
        $checked = 0;

        foreach ($families as $family) {
            $expected = self::EXPECTED[$family] ?? null;
            if ($expected === null) {
                $this->warn("Для семьи «{$family}» эталона нет — сверять не с чем.");

                continue;
            }

            $actual = $report->forFamily($family);
            if ($actual === null) {
                $this->warn("Семья «{$family}» в этой базе не заведена (ни одного курса) — пропускаю.");

                continue;
            }

            $checked++;
            $failures += $this->compare($family, $expected, $actual);
        }

        if ($checked === 0) {
            $this->info('Сверять нечего: ни одна семья из эталона в этой базе не заведена.');

            return self::SUCCESS;
        }

        if ($failures > 0) {
            $this->error("Расхождений с эталоном: {$failures}. Это стоп-условие 1 контракта H3083 — данные изменились, план надо пересверить.");

            return self::FAILURE;
        }

        $this->info('Сверка сошлась с эталоном полностью.');

        return self::SUCCESS;
    }

    /**
     * Слаг семьи из опции. Точное совпадение, иначе — единственный похожий
     * кандидат (эталон писался человеком и мог разойтись с транслитерацией
     * `Str::slug`, которой заведены боевые слаги).
     */
    private function resolveFamily(string $input): ?string
    {
        $input = trim($input);
        if (isset(self::EXPECTED[$input])) {
            return $input;
        }

        $close = [];
        foreach (array_keys(self::EXPECTED) as $known) {
            similar_text($input, $known, $percent);
            if ($percent >= 75.0) {
                $close[] = $known;
            }
        }

        if (count($close) === 1) {
            $this->line("Семьи «{$input}» нет; трактую как «{$close[0]}».");

            return $close[0];
        }

        $this->error("Семья «{$input}» неизвестна. Эталон есть для: ".implode(', ', array_keys(self::EXPECTED)).'.');

        return null;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function compare(string $family, array $expected, array $actual): int
    {
        $this->newLine();
        $this->info("Семья «{$family}» — {$actual['family_title']}");

        $rows = [];
        $failures = 0;
        $check = function (string $what, $exp, $got) use (&$rows, &$failures): void {
            $ok = is_float($exp) || is_float($got)
                ? abs((float) $exp - (float) $got) < 0.01
                : $exp === $got;
            $rows[] = [$what, $this->fmt($exp), $this->fmt($got), $ok ? 'ok' : 'РАСХОЖДЕНИЕ'];
            if (! $ok) {
                $failures++;
            }
        };

        $byId = [];
        foreach ($actual['streams'] as $stream) {
            $byId[$stream['course_id']] = $stream;
        }

        foreach ($expected['streams'] as $courseId => $exp) {
            $got = $byId[$courseId] ?? null;
            if ($got === null) {
                $rows[] = ["курс {$courseId}", 'есть', 'НЕТ в семье', 'РАСХОЖДЕНИЕ'];
                $failures++;

                continue;
            }

            $check("курс {$courseId} · плательщиков", $exp['payers'], $got['payers']);
            $check("курс {$courseId} · выручка", (float) $exp['revenue'], (float) $got['revenue']);
            $check("курс {$courseId} · начислено (валовое)", (float) $exp['accrued'], (float) $got['accrued']);

            $blocks = [];
            foreach ($got['blocks'] as $b) {
                $blocks[$b['number']] = $b;
            }
            foreach ($exp['blocks'] as $n => $buyers) {
                $check("курс {$courseId} · блок {$n} · купили", $buyers, $blocks[$n]['buyers'] ?? 0);
            }
            foreach ($exp['block_revenue'] as $n => $revenue) {
                $check("курс {$courseId} · блок {$n} · выручка", (float) $revenue, (float) ($blocks[$n]['revenue'] ?? 0));
            }
        }

        foreach ($expected['crossover'] as [$a, $b, $count]) {
            $pair = null;
            foreach ($actual['crossover']['pairs'] as $p) {
                if (($p['from_course_id'] === $a && $p['to_course_id'] === $b)
                    || ($p['from_course_id'] === $b && $p['to_course_id'] === $a)) {
                    $pair = $p;
                    break;
                }
            }
            $check("пересечение {$a} ∩ {$b}", $count, $pair['count'] ?? 0);
        }

        foreach ($expected['recording'] as $courseId => $exp) {
            $rec = null;
            foreach ($actual['crossover']['recording'] as $r) {
                if ($r['course_id'] === $courseId) {
                    $rec = $r;
                    break;
                }
            }
            $check("запись {$courseId} · покупателей", $exp['buyers'], $rec['buyers'] ?? 0);
            $check("запись {$courseId} · из них с живого потока", $exp['also_live'], $rec['also_live'] ?? 0);
            $check("запись {$courseId} · только запись", $exp['only_recording'], $rec['only_recording'] ?? 0);
        }

        $this->table(['Показатель', 'Эталон', 'Посчитано', 'Итог'], $rows);

        $salary = $actual['salary'];
        $this->line(sprintf(
            'Начислено (валовое) %s ₽ · выплачено подтверждённо %s ₽ · остаток %s ₽ (%s) · ждут подтверждения %s ₽ в %d строках.',
            $this->fmt($salary['accrued']),
            $this->fmt($salary['paid_out']),
            $this->fmt($salary['remainder']),
            $salary['attribution_confirmed'] ? 'подтверждено' : 'ПРЕДВАРИТЕЛЬНО',
            $this->fmt($salary['pending_total']),
            count($salary['pending_candidates']),
        ));

        $att = $actual['attendance'];
        $this->line(sprintf(
            'Покрытие посещаемости: %d из %d (%d %%).',
            $att['covered_users'],
            $att['total_users'],
            (int) round($att['coverage_ratio'] * 100),
        ));

        return $failures;
    }

    private function fmt(mixed $v): string
    {
        return is_float($v) ? number_format($v, 2, '.', ' ') : (string) $v;
    }
}
