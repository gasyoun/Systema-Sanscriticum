<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\IpExpenseCategory;
use App\Models\IpExpense;
use App\Models\IpExpenseAudit;
use App\Services\BankStatements\BankStatementParser;
use App\Services\BankStatements\PayPalCsvParser;
use App\Services\BankStatements\SberBizCsvParser;
use App\Services\BankStatements\TochkaDebitsCsvParser;
use App\Services\BankStatements\UnparseableStatementException;
use Illuminate\Console\Command;

/**
 * Месячный импорт банковской выписки в «Расходы ИП» (H4200) — контур
 * новых расходов после остановки книги (июль 2026): Точка (канонический
 * CSV дебета сборщика Uprava), Сбер (расширенная выписка CSV), PayPal
 * (Activity export CSV). Без ручного ввода: парсер → паритет-гейты →
 * dry-run отчёт → --apply.
 *
 * Гейты до записи (все REFUSE-ные, ничего не пишется):
 *  - месяц файла обязан совпасть с --month и не попадать в окно двойного
 *    счёта (≤ 2026-07: книга Oct'25–Jul'26 уже в ip_expenses + CRM-«Расход»
 *    pseudo-payments — AUDIT_BOOKKEEPING_MISERABLE_MAP §3); оверрайд только
 *    осознанный, --overlap-acknowledged;
 *  - --aggregates (tochka_monthly_aggregates.tsv): Σ дебета месяца из
 *    свежайшего снапшота == Σ RUB-строк файла;
 *  - --expect-total CUR=СУММА: по валюте, сколько сказала выписка.
 *
 * Идемпотентность — конвенции книги (H4188): import_hash =
 * sha1(source_tab|дата|получатель|сумма|валюта|№вхождения); повторный
 * прогон доливает только новое. Категория — эвристика guess, оператор
 * пере-категоризует в админке. Аудит — action imported_statement.
 *
 * Без --apply — только отчёт (dry-run), ничего не пишется. Первый реальный
 * --apply на проде — human-gated (standing deploy rule).
 */
class ImportIpStatementExpenses extends Command
{
    protected $signature = 'ip-expenses:import-statement
        {bank : tochka | sber | paypal}
        {file : CSV-файл выписки}
        {--month= : Месяц выписки YYYY-MM (обязателен: провенанс + гейты)}
        {--apply : Записать импорт; без флага — только отчёт (dry-run)}
        {--aggregates= : TSV месячных агрегатов Точки — гейт Σ дебета месяца}
        {--expect-total=* : Ожидаемая Σ по валюте: RUB=70080.00 (повторяемо)}
        {--account= : Метка счёта в ip_expenses (по умолчанию — по банку)}
        {--overlap-acknowledged : Разрешить месяц из окна двойного счёта (≤ 2026-07)}';

    protected $description = 'Импорт банковской выписки (Точка/Сбер/PayPal) в ip_expenses — dry-run по умолчанию, паритет-гейты, идемпотентно (H4200)';

    /** Последний месяц, покрытый книгой + CRM-«Расход» (окно двойного счёта). */
    private const OVERLAP_LAST_MONTH = '2026-07';

    private const BANKS = [
        'tochka' => ['label' => 'Точка', 'account' => 'Точка ИП', 'parser' => TochkaDebitsCsvParser::class],
        'sber' => ['label' => 'Сбер', 'account' => 'Сбер бизнес', 'parser' => SberBizCsvParser::class],
        'paypal' => ['label' => 'PayPal', 'account' => 'PayPal', 'parser' => PayPalCsvParser::class],
    ];

    /** Человеческие подписи счётчиков парсера — для отчёта dry-run. */
    private const STAT_LABELS = [
        'skipped_summary' => 'итоговых строк («Итого»)',
        'skipped_credit' => 'кредитных строк (не расход)',
        'skipped_income' => 'входящих PayPal (не расход)',
        'skipped_status' => 'не Completed (Pending/Denied/…)',
        'skipped_internal' => 'внутренних переводов на свой счёт',
        'ambiguous_dates' => 'неоднозначных дат D/M vs M/D (прочитано как M/D)',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $bank = (string) $this->argument('bank');
        $month = (string) $this->option('month');

        if (! isset(self::BANKS[$bank])) {
            $this->error('Неизвестный банк «'.$bank.'» — ожидался один из: '.implode(', ', array_keys(self::BANKS)).'. REFUSE.');

            return self::FAILURE;
        }

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $this->error('--month обязателен в формате YYYY-MM (провенанс и гейты двойного счёта). REFUSE.');

            return self::FAILURE;
        }

        if ($month <= self::OVERLAP_LAST_MONTH && ! $this->option('overlap-acknowledged')) {
            $this->error("Месяц {$month} попадает в окно двойного счёта (≤ ".self::OVERLAP_LAST_MONTH.'): книга «Расходы по ИП» Oct\'25–Jul\'26 уже лежит в ip_expenses, CRM-«Расход» pseudo-payments добавляет третий счёт — сверка @DECIDE открыта (AUDIT_BOOKKEEPING_MISERABLE_MAP §3). Осознанный оверрайд: --overlap-acknowledged. REFUSE.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Файл выписки не найден/не читается: {$path}. REFUSE.");

            return self::FAILURE;
        }

        $config = self::BANKS[$bank];
        $contents = (string) file_get_contents($path);
        $fileSha256 = hash('sha256', $contents);

        /** @var BankStatementParser $parser */
        $parser = app($config['parser']);

        try {
            ['rows' => $rows, 'stats' => $stats] = $parser->parse($contents);
        } catch (UnparseableStatementException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Месяц каждой строки обязан совпасть с --month: файл не того месяца —
        // это тоже паритетное расхождение, не «ну примерно».
        $wrongMonth = array_values(array_filter($rows, fn (array $r): bool => substr($r['date'], 0, 7) !== $month));
        if ($wrongMonth !== []) {
            $this->error('Строки не из месяца '.$month.' (например, '.$wrongMonth[0]['date'].'): файл не соответствует --month. REFUSE.');

            return self::FAILURE;
        }

        // Σ по валютам — Decimal-exact, только RUB участвует в рублёвых гейтах.
        $sums = [];
        foreach ($rows as $row) {
            $sums[$row['currency']] = bcadd($sums[$row['currency']] ?? '0', $row['amount'], 2);
        }

        if (array_key_exists('RUB', $sums) && $this->option('aggregates') !== null) {
            if (! $this->aggregatesGate((string) $this->option('aggregates'), $month, $sums['RUB'])) {
                $this->error('Паритет с месячными агрегатами Точки нарушен — импорт НЕ выполнялся.');

                return self::FAILURE;
            }
        }

        foreach ((array) $this->option('expect-total') as $expectation) {
            if (preg_match('/^([A-Z]{3})=(-?\d+(?:[.,]\d+)?)$/', (string) $expectation, $m) !== 1) {
                $this->error("--expect-total «{$expectation}» не вида CUR=СУММА. REFUSE.");

                return self::FAILURE;
            }

            $currency = $m[1];
            $expected = bcadd(str_replace(',', '.', $m[2]), '0', 2);
            $actual = $sums[$currency] ?? '0.00';

            if (bccomp($actual, $expected, 2) !== 0) {
                $this->error("Гейт {$currency}: Σ файла {$actual} != заявленная выпиской {$expected}. REFUSE.");

                return self::FAILURE;
            }
        }

        $this->doubleCountWarning($month, $apply);

        // Маппинг → ip_expenses, идемпотентно (конвенции книги H4188).
        $sourceTab = 'Выписка '.$config['label'].' '.$month;
        $account = (string) ($this->option('account') ?: $config['account']);
        $occurrences = [];
        $created = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $key = $row['date'].'|'.$row['payee'].'|'.$row['amount'].'|'.$row['currency'];
            $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
            $hash = hash('sha1', $sourceTab.'|'.$key.'|'.$occurrences[$key]);

            if (IpExpense::query()->where('import_hash', $hash)->exists()) {
                $skipped++;

                continue;
            }

            if (! $apply) {
                $created++;

                continue;
            }

            $expense = IpExpense::query()->create([
                'spent_at' => $row['date'],
                'payee' => $row['payee'],
                'amount' => $row['amount'],
                'currency' => $row['currency'],
                'fx_note' => $row['currency'] !== 'RUB' ? '-'.$row['amount'].' '.$row['currency'].' (PayPal)' : null,
                'account' => $account,
                // Категория только по содержимому строки (получатель +
                // назначение): метка банка в guess не идёт — «Точка» в
                // эвристике Bank слил бы каждую строку в «Банк».
                'category' => IpExpenseCategory::guess($row['payee'], (string) $row['description'])->value,
                'note' => $row['description'],
                'source_tab' => $sourceTab,
                'import_hash' => $hash,
            ]);

            $expense->audits()->create([
                'admin_id' => null,
                'admin_name' => 'Система',
                'action' => IpExpenseAudit::ACTION_IMPORTED_STATEMENT,
                'amount' => $expense->amount,
                'changes' => [
                    '_statement' => $config['label'],
                    '_month' => $month,
                    '_file_sha256' => $fileSha256,
                    '_source_tab' => $sourceTab,
                ],
                'created_at' => now(),
            ]);

            $created++;
        }

        $mode = $apply ? 'ЗАПИСАНО' : 'dry-run';
        $this->info("[{$mode}] выписка {$config['label']} {$month}: новых строк {$created}, уже в базе: {$skipped}.".($apply ? '' : ' Ничего не записано — для записи запустите с --apply.'));

        $tableRows = [];
        foreach ($sums as $currency => $sum) {
            $tableRows[] = [$currency, (string) count(array_filter($rows, fn (array $r): bool => $r['currency'] === $currency)), $sum];
        }

        if ($tableRows !== []) {
            $this->table(['Валюта', 'Строк', 'Σ расходов'], $tableRows);
        }

        foreach ($stats as $stat => $count) {
            if ($count > 0) {
                $this->line('пропущено: '.(self::STAT_LABELS[$stat] ?? $stat).' — '.$count);
            }
        }

        if (! $apply) {
            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    /** Гейт Σ дебета месяца из tochka_monthly_aggregates.tsv (свежайший снапшот). */
    private function aggregatesGate(string $tsvPath, string $month, string $rubSum): bool
    {
        if (! is_file($tsvPath)) {
            $this->error("Нет файла агрегатов: {$tsvPath}. REFUSE.");

            return false;
        }

        $rows = [];
        $header = null;
        foreach (file($tsvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $cells = explode("\t", $line);
            if ($header === null) {
                $header = $cells;

                continue;
            }

            $rows[] = array_combine($header, $cells) ?: [];
        }

        $monthRows = array_values(array_filter(
            $rows,
            fn (array $r): bool => ($r['month'] ?? null) === $month,
        ));

        if ($monthRows === []) {
            $this->error("В агрегатах нет месяца {$month} — гейт невозможен. Сначала pull_tochka_statement.py за этот месяц. REFUSE.");

            return false;
        }

        $latest = end($monthRows);

        if (str_starts_with((string) $latest['status'], 'error')) {
            $this->error("Снапшот агрегатов за {$month} со статусом {$latest['status']} — гейт на битых данных не строится. REFUSE.");

            return false;
        }

        $declared = bcadd((string) ($latest['debit_sum'] ?: '0'), '0', 2);

        if (bccomp($rubSum, $declared, 2) !== 0) {
            $this->error("Гейт агрегатов: Σ RUB файла {$rubSum} != debit_sum месяца {$declared} (снапшот {$latest['snapshot_utc']}). REFUSE.");

            return false;
        }

        return true;
    }

    /** Предупреждение о двойном счёте — на каждом прогоне, dry-run и apply. */
    private function doubleCountWarning(string $month, bool $apply): void
    {
        $this->warn('Двойной счёт: строки выписки НЕ суммировать с CRM-«Расход» (pseudo-payments) и с книгой «Расходы по ИП» — сверка @DECIDE открыта (AUDIT_BOOKKEEPING_MISERABLE_MAP §3).'.($month <= self::OVERLAP_LAST_MONTH ? ' Месяц '.self::OVERLAP_LAST_MONTH.' и раньше — окно двойного счёта, вы пошли с --overlap-acknowledged.' : ''));
    }
}
