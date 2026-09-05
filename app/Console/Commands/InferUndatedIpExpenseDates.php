<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IpExpense;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Вывод дат для undated-строк «Расходов ИП» (H4188 residual, рулинг MG
 * 05-09: «I am not accountant» — ручное проставление дат отменено).
 *
 * Источник месяца — сама книга: строка без даты лежит во вкладке месяца
 * (source_tab: «Февраль 2026»), месяц-закрытия (комиссии банка, налог
 * «предположительный расчёт», «пока предварительно») в книге писались
 * последними днями месяца. Детерминированное правило:
 *
 *   spent_at = max(dated spent_at той же вкладки), если есть;
 *   иначе последний календарный день месяца вкладки.
 *
 * Ничего не выдумывается сверх вкладки: день внутри месяца остаётся
 * неизвестен — берётся граница месяца по данным той же вкладки. Каждая
 * правка уходит в ip_expense_audits обсервером (null → дата, «Система»).
 * Dry-run по умолчанию; писать — с --apply. Повторный прогон — no-op
 * (undated больше нет).
 */
class InferUndatedIpExpenseDates extends Command
{
    protected $signature = 'ip-expenses:infer-undated-dates
        {--apply : Записать выведенные даты; без флага — только отчёт}';

    protected $description = 'Вывести spent_at для undated-строк «Расходов ИП» из месяца их вкладки книги (dry-run по умолчанию)';

    /** Русские названия месяцев вкладок книги (стемы). */
    private const MONTHS = [
        'январ' => 1, 'феврал' => 2, 'март' => 3, 'апрел' => 4,
        'ма' => 5, 'июн' => 6, 'июл' => 7, 'август' => 8,
        'сентябр' => 9, 'октябр' => 10, 'ноябр' => 11, 'декабр' => 12,
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $undated = IpExpense::query()
            ->whereNull('spent_at')
            ->orderBy('source_tab')
            ->orderBy('payee')
            ->get();

        if ($undated->isEmpty()) {
            $this->info('Undated-строк нет — выводить нечего.');

            return self::SUCCESS;
        }

        // Кэш: [source_tab => max dated spent_at той же вкладки].
        $tabs = IpExpense::query()
            ->whereNotNull('spent_at')
            ->selectRaw('source_tab, max(spent_at) as max_date')
            ->groupBy('source_tab')
            ->pluck('max_date', 'source_tab');

        $plan = [];
        foreach ($undated as $row) {
            $inferred = $this->inferFor($row->source_tab, $tabs);
            if ($inferred === null) {
                $this->warn("«{$row->payee}» ({$row->source_tab}): месяц вкладки не распознан — пропущен, правится руками.");

                continue;
            }
            $plan[] = ['row' => $row, 'date' => $inferred];
        }

        if ($plan === []) {
            $this->warn('Ни одной строки вывести не удалось.');

            return self::FAILURE;
        }

        $byMonth = [];
        foreach ($plan as ['row' => $row, 'date' => $date]) {
            $this->line(sprintf(
                '%s | %s | %s ₽ | NULL → %s',
                $row->source_tab,
                mb_substr((string) $row->payee, 0, 40),
                $row->amount,
                $date->toDateString(),
            ));
            $key = $date->format('Y-m');
            $byMonth[$key] = bcadd($byMonth[$key] ?? '0', (string) $row->amount, 2);
        }

        $this->table(['Месяц вывода', 'Σ, ₽'], collect($byMonth)
            ->sortKeys()
            ->map(fn (string $sum, string $m): array => [$m, $sum])
            ->values()->all());

        if (! $apply) {
            $this->info(sprintf(
                'Dry-run: %d строк(а) получили бы дату. Запустите с --apply.',
                count($plan),
            ));

            return self::SUCCESS;
        }

        foreach ($plan as ['row' => $row, 'date' => $date]) {
            $row->spent_at = $date->toDateString();
            $row->save();
        }

        $this->info('[ЗАПИСАНО] дат выведено: '.count($plan).'; осталось undated: '.IpExpense::query()->whereNull('spent_at')->count().'.');

        return self::SUCCESS;
    }

    private function inferFor(string $tab, $tabs): ?Carbon
    {
        // 1) Последняя датированная строка той же вкладки.
        $max = $tabs[$tab] ?? null;
        if ($max !== null) {
            return Carbon::parse($max)->startOfDay();
        }

        // 2) Последний день месяца вкладки.
        $month = $this->parseTabMonth($tab);
        if ($month === null) {
            return null;
        }

        return Carbon::create((int) $month[1], $month[0])->endOfMonth()->startOfDay();
    }

    /**
     * «Февраль 2026» → [2, 2026]; «2026-02» → [2, 2026]; иначе null.
     *
     * @return array{int, int}|null
     */
    private function parseTabMonth(string $tab): ?array
    {
        $trimmed = trim($tab);

        if (preg_match('/^(\d{4})-(\d{2})$/', $trimmed, $m) === 1) {
            return [(int) $m[2], (int) $m[1]];
        }

        $lower = mb_strtolower($trimmed);
        foreach (self::MONTHS as $stem => $num) {
            if (mb_strpos($lower, $stem) !== false
                && preg_match('/(\d{4})/u', $lower, $y) === 1) {
                return [$num, (int) $y[1]];
            }
        }

        return null;
    }
}
