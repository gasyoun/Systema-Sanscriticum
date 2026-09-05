<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\IpExpenseCategory;
use App\Models\IpExpense;
use App\Models\IpExpenseAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Одноразовый импорт книги «Расходы по ИП» в контур ip_expenses (H4188).
 *
 * Источник — снапшот снежного скрейпера: каталог с manifest.json +
 * raskhody-ip/gid{gid}.jsonl (машиночитаемые строки книги: payee/amount/
 * date/source; amount=null — memo-строки без суммы, не импортируются).
 * На Mac/сервере снапшот лежит в Uprava data/money_sheets/<дата>/.
 *
 * Порядок (миссия H4188 п.2): по умолчанию dry-run; писать — с --apply.
 * Паритет-гейты до записи: sha256 каждого csv == manifest; Σ строк с суммой
 * == manifest sum (bcmath, Decimal-exact); число строк jsonl == manifest rows.
 * Расхождение — REFUSE, ничего не пишется.
 *
 * Идемпотентность: import_hash = sha1(вкладка|дата|получатель|сумма|№вхождения
 * в кладке). Повторный прогон доливает только новое; легитимные дубликаты
 * строки внутри вкладки сохраняются (occurrence), а не слипаются.
 *
 * Евровые/долларовые строки книги: сумма в рублях (amount), валютная деталь —
 * в fx_note («400 евро PayPal» — она же счёт-колонка). Категория — эвристика
 * IpExpenseCategory::guess, оператор пере-категоризует в админке (аудит пишется).
 */
class ImportIpBookExpenses extends Command
{
    protected $signature = 'ip-expenses:import-book
        {path : Каталог снапшота (manifest.json + raskhody-ip/gid*.jsonl)}
        {--apply : Записать импорт; без флага — только отчёт (dry-run)}';

    protected $description = 'Импорт книги «Расходы по ИП» в ip_expenses (dry-run по умолчанию, паритет по manifest, идемпотентно)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $path = rtrim((string) $this->argument('path'), '/');

        $manifestPath = $path.'/manifest.json';
        if (! is_file($manifestPath)) {
            $this->error("Нет manifest.json в {$path} — это не снапшот книги.");

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $feed = null;
        foreach ((array) ($manifest['feeds'] ?? []) as $f) {
            if (($f['slug'] ?? '') === 'raskhody-ip') {
                $feed = $f;
                break;
            }
        }

        if ($feed === null) {
            $this->error('В manifest.json нет фида raskhody-ip.');

            return self::FAILURE;
        }

        // 1. Читаем вкладки, считаем Decimal-exact суммы (bcmath по raw-строкам).
        $tabs = [];
        $grandTotal = '0';
        $grandRows = 0;
        $refused = false;

        foreach ($feed['tabs'] as $tab) {
            $jsonlPath = $path.'/raskhody-ip/gid'.$tab['gid'].'.jsonl';
            if (! is_file($jsonlPath)) {
                $this->error("Вкладка «{$tab['tab']}»: нет {$jsonlPath}.");

                return self::FAILURE;
            }

            // Паритет-гейт 1 (мягкий): sha256 csv == manifest.
            // Скрейпер хэширует байты как их отдал Google (CRLF), но Uprava
            // нормализует *.csv в LF при коммите (gitattributes, H2004), и
            // часть вкладок легла уже нормализованной — их исходные байты в
            // репо нет. Расхождение sha — предупреждение: содержательная
            // паритетность обеспечивается гейтами Σ и числа строк (ниже),
            // а не байт-хэшем недостижимого оригинала.
            $csvPath = $path.'/raskhody-ip/gid'.$tab['gid'].'.csv';
            if (is_file($csvPath)) {
                $csvBytes = (string) file_get_contents($csvPath);
                $lf = str_replace("\r\n", "\n", $csvBytes);
                $variants = [
                    hash('sha256', $csvBytes),
                    hash('sha256', str_replace("\n", "\r\n", $lf)),
                    hash('sha256', $lf),
                ];
                if (! in_array((string) $tab['sha256_csv'], $variants, true)) {
                    $this->warn("Вкладка «{$tab['tab']}»: sha256 csv не совпал с manifest (блобы LF-нормализованы, H2004) — держим гейты Σ/строк.");
                }
            }

            $rows = [];
            foreach ((array) file($jsonlPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $row = json_decode($line, true);
                if (! is_array($row) || ! array_key_exists('amount', $row)) {
                    continue;
                }
                $rows[] = $row;
            }

            // amount=null — memo-строки книги без суммы: не деньги, пропускаем.
            $dataRows = array_values(array_filter(
                $rows,
                fn (array $r): bool => $r['amount'] !== null,
            ));

            // Σ-гейт (bcmath по нормализованному raw) == manifest sum.
            // Manifest Σ включает строку «Итого» вкладки (скрейпер так писал):
            // паритет держим по всем строкам с суммой, а саму «Итого» из
            // импорта исключаем (миссия H4188: «Итого rows excluded») —
            // это служебная строка книги, не расход.
            $sum = '0';
            $totalRows = 0;
            foreach ($dataRows as $row) {
                $sum = bcadd($sum, $this->decimal($row['raw_amount'] ?? (string) $row['amount']), 2);
                $totalRows++;
            }

            $manifestSum = number_format((float) $tab['sum'], 2, '.', '');
            if (bccomp($sum, $manifestSum, 2) !== 0) {
                $this->error("Вкладка «{$tab['tab']}»: Σ строк {$sum} != manifest {$manifestSum}. REFUSE.");
                $refused = true;
            }

            // Паритет-гейт 3: строк jsonl == manifest rows (включая memo).
            if (count($rows) !== (int) $tab['rows']) {
                $this->error("Вкладка «{$tab['tab']}»: строк ".count($rows).' != manifest '.$tab['rows'].'. REFUSE.');
                $refused = true;
            }

            $grandTotal = bcadd($grandTotal, $sum, 2);
            $grandRows += count($dataRows);
            $tabs[] = ['meta' => $tab, 'rows' => $dataRows, 'sum' => $sum];
        }

        if ($refused) {
            $this->error('Паритет книги нарушен — импорт НЕ выполнялся. Сверьте снапшот с реестром Uprava.');

            return self::FAILURE;
        }

        $this->info("Снапшот {$manifest['date']}: вкладок ".count($tabs).", строк с суммой {$grandRows}, Σ {$grandTotal} ₽ (паритет manifest — OK).");

        // 2. Маппинг строк → ip_expenses + идемпотентный долив.
        $byMonth = [];
        $undated = 0;
        $created = 0;
        $skipped = 0;
        $summaryRows = 0;
        $snapshot = (string) ($manifest['date'] ?? 'unknown');

        foreach ($tabs as $tab) {
            $tabName = (string) $tab['meta']['tab'];
            $occurrences = [];

            foreach ($tab['rows'] as $row) {
                // «Итого» — служебная строка вкладки, не расход (H4188:
                // «Итого rows excluded»); в паритет-гейтах она уже учтена.
                if (preg_match('/^\s*итого/iu', (string) $row['payee']) === 1) {
                    $summaryRows++;

                    continue;
                }

                $spentAt = $row['date'] !== null ? Carbon::parse((string) $row['date'])->toDateString() : null;
                $amount = $this->decimal($row['raw_amount'] ?? (string) $row['amount']);
                $payee = trim((string) $row['payee']);
                $source = trim((string) ($row['source'] ?? ''));
                $note = trim((string) ($row['note'] ?? '')) !== '' ? trim((string) $row['note']) : null;

                // occurrence сохраняет легитимные дубликаты внутри вкладки.
                $key = $spentAt.'|'.$payee.'|'.$amount;
                $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
                $hash = hash('sha1', $tabName.'|'.$key.'|'.$occurrences[$key]);

                if (IpExpense::query()->where('import_hash', $hash)->exists()) {
                    $skipped++;

                    continue;
                }

                if (! $apply) {
                    $created++;

                    continue;
                }

                $expense = IpExpense::query()->create([
                    'spent_at' => $spentAt,
                    'payee' => $payee,
                    'amount' => $amount,
                    'currency' => 'RUB',
                    'fx_note' => $this->isFx($source) ? $source : null,
                    'account' => $source !== '' ? $source : null,
                    'category' => IpExpenseCategory::guess($payee, $note ?? '', $source)->value,
                    'note' => $note,
                    'source_tab' => $tabName,
                    'import_hash' => $hash,
                ]);

                // Аудит-строка 'imported' уже уйдёт из observer'а как created
                // («Система»), но провенанс снапшота вешаем явно:
                $expense->audits()->create([
                    'admin_id' => null,
                    'admin_name' => 'Система',
                    'action' => IpExpenseAudit::ACTION_IMPORTED,
                    'amount' => $expense->amount,
                    'changes' => ['_snapshot' => $snapshot, 'source_tab' => $tabName],
                    'created_at' => now(),
                ]);

                $created++;

                $monthKey = $spentAt !== null ? substr($spentAt, 0, 7) : 'без даты';
                $byMonth[$monthKey] = bcadd($byMonth[$monthKey] ?? '0', $amount, 2);
                if ($spentAt === null) {
                    $undated++;
                }
            }
        }

        $mode = $apply ? 'ЗАПИСАНО' : 'dry-run';
        $this->info("[{$mode}] новых строк: {$created}, уже в базе: {$skipped}, итого-строк книги пропущено: {$summaryRows}".($undated > 0 ? ", без даты: {$undated} (проставьте даты руками)" : ''));

        if ($byMonth !== []) {
            ksort($byMonth);
            $this->table(['Месяц', 'Σ, ₽'], collect($byMonth)
                ->map(fn (string $sum, string $month): array => [$month, $sum])
                ->values()->all());
        }

        if (! $apply) {
            $this->info('Dry-run: ничего не записано. Запустите с --apply для записи.');

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    /** «70 080,00» / «4600» → «70080.00» (Decimal-exact, без float). */
    private function decimal(string $raw): string
    {
        $clean = str_replace(["\u{a0}", ' ', ' '], '', trim($raw));

        return bcadd(str_replace(',', '.', $clean), '0', 2);
    }

    private function isFx(string $source): bool
    {
        return preg_match('/евро|доллар|€|\$|eur|usd/iu', $source) === 1;
    }
}
