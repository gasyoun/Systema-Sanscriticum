<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\CourseFamilyMatcher;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * H3083 — выгрузка экрана «Потоки курса» в Excel.
 *
 * Один лист, строка на студента, колонки — блоки каждого потока.
 *
 * Почему не Filament\Actions\Exports\Exporter (как TeacherSalariesExporter):
 * приёмка требует, чтобы ПЛАШКА ПОКРЫТИЯ посещаемости попала в файл отдельной
 * строкой заголовка. Экспортёр Filament строит таблицу «колонки × строки
 * модели» и произвольной шапки над ней не допускает — плашка бы потерялась, а
 * пустая колонка посещаемости без неё читается как «никто не ходил». Механизм
 * взят не новый: `maatwebsite/excel` уже используется в PaymentImportService.
 */
class CourseStreamComparisonExport implements FromArray, WithTitle
{
    /** @param array<string, mixed> $report */
    public function __construct(private readonly array $report) {}

    public function download(): BinaryFileResponse
    {
        $name = 'potoki-'.Str::slug((string) $this->report['family']).'-'.now()->format('Y-m-d').'.xlsx';

        return Excel::download($this, $name);
    }

    public function title(): string
    {
        return 'Потоки курса';
    }

    /** @return array<int, array<int, string|int|float|null>> */
    public function array(): array
    {
        $streams = $this->report['streams'];
        $attendance = $this->report['attendance'];
        $salary = $this->report['salary'];

        $rows = [];

        // --- шапка: контекст, без которого цифры читаются неверно -----------
        $rows[] = ['Потоки курса: '.$this->report['family_title'], 'семья: '.$this->report['family']];
        $rows[] = ['Выгружено', now()->format('d.m.Y H:i')];
        $rows[] = [$this->coverageBadge($attendance)];
        $rows[] = [$this->salaryBadge($salary)];
        $rows[] = [];

        // --- сводка по потокам ----------------------------------------------
        $rows[] = ['Поток', 'Роль', 'Плательщиков', 'Выручка, ₽', 'Средний чек, ₽', 'Скидки, ₽', 'Начислено (валовое), ₽', 'Удержание 1→последний, %'];
        foreach ($streams as $s) {
            $rows[] = [
                $s['title'],
                $this->roleLabel($s['role']),
                $s['payers'],
                $s['revenue'],
                $s['avg_check'],
                $s['discount_total'],
                $s['accrued'],
                $s['retention_first_to_last'] ?? '—',
            ];
        }
        $rows[] = [];

        // --- блоки ------------------------------------------------------------
        $rows[] = ['Поток', 'Блок', 'Купили блок', 'Имеют доступ', 'Выручка блока, ₽'];
        foreach ($streams as $s) {
            foreach ($s['blocks'] as $b) {
                $rows[] = [$s['title'], 'Блок '.$b['number'], $b['buyers'], $b['access'], $b['revenue']];
            }
        }
        $rows[] = [];

        // --- строка на студента × блоки всех потоков ---------------------------
        $header = ['Студент', 'ID'];
        foreach ($streams as $s) {
            foreach ($s['blocks'] as $b) {
                $header[] = $this->shortTitle($s['title']).' · блок '.$b['number'];
            }
        }
        $header[] = 'Потоков куплено';
        $header[] = 'Есть отметка посещаемости';
        $rows[] = $header;

        foreach ($this->studentMatrix($streams) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Матрица «студент × блоки всех потоков»: строка на каждого плательщика
     * семьи, крестик в блоке, к которому у него есть доступ.
     *
     * @param  list<array<string, mixed>>  $streams
     * @return list<array<int, string|int>>
     */
    private function studentMatrix(array $streams): array
    {
        /** @var array<int, string> $names */
        $names = [];
        /** @var array<int, array<int, array<int, bool>>> $byStream  course_id => user_id => block => bool */
        $byStream = [];

        foreach ($streams as $s) {
            foreach ($s['students'] as $student) {
                $names[$student['id']] = $student['name'];
                $byStream[$s['course_id']][$student['id']] = $student['blocks'];
            }
        }

        // «нет отметки посещаемости» — список из отчёта, а не второй запрос.
        $neverWatched = [];
        foreach ($this->report['attendance']['bought_all_never_watched'] as $u) {
            $neverWatched[$u['id']] = true;
        }

        asort($names);

        $rows = [];
        foreach ($names as $id => $name) {
            $row = [$name, $id];
            $streamsBought = 0;

            foreach ($streams as $s) {
                $blocks = $byStream[$s['course_id']][$id] ?? [];
                $inStream = false;
                foreach ($s['blocks'] as $b) {
                    $has = (bool) ($blocks[$b['number']] ?? false);
                    $row[] = $has ? '✓' : '';
                    $inStream = $inStream || $has;
                }
                if ($inStream) {
                    $streamsBought++;
                }
            }

            $row[] = $streamsBought;
            $row[] = isset($neverWatched[$id]) ? 'нет' : 'да';
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string, mixed> $attendance */
    private function coverageBadge(array $attendance): string
    {
        return sprintf(
            'Покрытие посещаемости: данные есть по %d из %d человек (%d %%). Пустая клетка посещаемости не означает «не ходил» — она означает «не собрано».',
            $attendance['covered_users'],
            $attendance['total_users'],
            (int) round($attendance['coverage_ratio'] * 100),
        );
    }

    /** @param array<string, mixed> $salary */
    private function salaryBadge(array $salary): string
    {
        if ($salary['attribution_confirmed']) {
            return sprintf('Остаток преподавателю: %s ₽ (разметка выплат подтверждена).', number_format((float) $salary['remainder'], 2, ',', ' '));
        }

        return sprintf(
            'Остаток преподавателю: %s ₽ — ПРЕДВАРИТЕЛЬНО. Не подтверждено %s ₽ в %d платежах-«Расходах»; пока их не разметил человек, остаток не является ответом.',
            number_format((float) $salary['remainder'], 2, ',', ' '),
            number_format((float) $salary['pending_total'], 2, ',', ' '),
            count($salary['pending_candidates']),
        );
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            CourseFamilyMatcher::ROLE_LIVE => 'живой поток',
            CourseFamilyMatcher::ROLE_RECORDING => 'в записи',
            default => 'не определена',
        };
    }

    private function shortTitle(string $title): string
    {
        return mb_strimwidth($title, 0, 28, '…');
    }
}
