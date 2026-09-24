<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reconciliation\DailyReconciler;
use App\Support\MoneySli\MoneySliAlerter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * H5445 (P3, D1): ежедневная оперативная сверка денег.
 *
 *   php artisan money:reconcile-daily                       # только чтение, вчерашний день
 *   php artisan money:reconcile-daily --date=2026-09-23 --json=/tmp/r.json
 *   php artisan money:reconcile-daily --all                 # вся история, только чтение
 *   php artisan money:reconcile-daily --persist --scheduled # плановый (04:35)
 *
 * Без --persist команда ничего не пишет — это доказывает счётчик SQL-записей
 * (любая запись = FAIL). С --persist пишет ТОЛЬКО таблицы money_recon_*
 * (любая другая запись = FAIL): сверка денег не создаёт и не меняет.
 *
 * Выход ≠ 0: дрейф контрольной суммы при том же входе, сломанное тождество
 * итогов, запрещённая запись. Незавершённость (нет источника) и новые
 * исключения — не падение, а громкий статус в отчёте и алерт при изменении.
 */
class MoneyReconcileDaily extends Command
{
    protected $signature = 'money:reconcile-daily
        {--date= : операционный день Y-m-d (по умолчанию вчера, Europe/Moscow)}
        {--all : всё окно истории до конца дня (только для ручного прогона)}
        {--persist : записать прогон и открыть исключения (money_recon_*)}
        {--scheduled : плановый запуск (флаг features.money_daily_reconciliation, heartbeat, алерты)}
        {--json= : сохранить полный отчёт в файл}
        {--dry-alert : не слать TG/heartbeat (для проверки)}';

    protected $description = 'H5445 (P3): ежедневная сверка доказательств с субрегистром — классы строк, исключения, контрольные суммы';

    public function handle(DailyReconciler $reconciler, MoneySliAlerter $alerter): int
    {
        $scheduled = (bool) $this->option('scheduled');
        $persist = (bool) $this->option('persist');
        $all = (bool) $this->option('all');

        if ($scheduled && ! config('features.money_daily_reconciliation')) {
            $this->comment('features.money_daily_reconciliation OFF — плановая сверка no-op до MONEY_DAILY_RECONCILIATION=true.');
            Log::warning('money_recon: daily reconciliation не вооружена (features.money_daily_reconciliation=false) — not_supported', [
                'check' => 'daily_reconciliation',
                'state' => 'not_supported',
            ]);

            return self::SUCCESS;
        }
        if ($all && $persist) {
            $this->error('--all только для ручного прогона без --persist: журнал ведётся по операционным дням.');

            return self::INVALID;
        }

        $tz = (string) config('app.timezone');
        $date = $this->option('date')
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('date'), $tz)
            : CarbonImmutable::now($tz)->subDay();
        if ($date === false) {
            $this->error('--date ожидает Y-m-d');

            return self::INVALID;
        }
        if ($all && ! $this->option('date')) {
            $date = CarbonImmutable::now($tz);
        }

        $writes = ['recon' => 0, 'other' => []];
        DB::listen(function ($q) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $q->sql) !== 1) {
                return;
            }
            if (preg_match('/^\s*(insert\s+into|replace\s+into|update|delete\s+from)\s+[`"]?money_recon_/i', $q->sql) === 1) {
                $writes['recon']++;

                return;
            }
            $writes['other'][] = substr($q->sql, 0, 120);
        });

        $mode = $scheduled ? 'scheduled' : 'manual';
        $report = $reconciler->run($date, $all, $persist, $mode);
        $report['writes'] = ['money_recon' => $writes['recon'], 'other' => count($writes['other']), 'other_sample' => array_slice($writes['other'], 0, 5)];

        $forbiddenWrite = $writes['other'] !== [] || (! $persist && $writes['recon'] > 0);
        $drift = ($report['outcome'] ?? null) === DailyReconciler::OUTCOME_DRIFT;
        $identityBroken = ! $report['identity_ok'];
        $ok = ! $forbiddenWrite && ! $drift && ! $identityBroken;

        if ($json = $this->option('json')) {
            file_put_contents((string) $json, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $this->printSummary($report);

        if ($scheduled) {
            $this->signal($alerter, $report, $ok, $forbiddenWrite, $drift, $identityBroken);
        }

        if ($forbiddenWrite) {
            $this->error('FAIL: запрещённая запись — '.($writes['other'][0] ?? 'money_recon_* без --persist'));
        }
        if ($drift) {
            $this->error("FAIL: дрейф — тот же вход (run #{$report['run_id']}), контрольная сумма {$report['totals_checksum']} ≠ сохранённой {$report['stored_checksum']}");
        }
        if ($identityBroken) {
            $this->error('FAIL: тождество итогов нарушено: '.json_encode($report['totals']['identity']));
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $report */
    private function printSummary(array $report): void
    {
        $t = $report['totals'];
        $this->line(sprintf(
            'Сверка %s%s — %s, исход %s; строк %d (%s ₽), находок %d, новых исключений %d',
            $report['business_date'],
            $report['all_history'] ? ' (вся история)' : '',
            $report['status'],
            $report['outcome'],
            $t['rows'],
            number_format($t['kopecks'] / 100, 2, '.', ' '),
            $report['findings'],
            $report['new_exceptions'],
        ));
        foreach ($report['sources'] as $name => $s) {
            $this->line(sprintf('  источник %-16s %s%s', $name, $s['status'], ! empty($s['note']) ? ' — '.$s['note'] : ''));
        }
        foreach ($t['classes'] as $class => $c) {
            $this->line(sprintf('  класс %-40s %6d строк  %14s ₽', $class, $c['rows'], number_format($c['kopecks'] / 100, 2, '.', ' ')));
        }
        $this->line('  исключено из сверки: '.json_encode($t['excluded'], JSON_UNESCAPED_UNICODE));
        $this->line('  тождества: '.json_encode($t['identity']));
        $this->line('  отпечаток входа '.$report['input_fingerprint']);
        $this->line('  контрольная сумма '.$report['totals_checksum']);
    }

    /** @param array<string, mixed> $report */
    private function signal(MoneySliAlerter $alerter, array $report, bool $ok, bool $forbiddenWrite, bool $drift, bool $identityBroken): void
    {
        $dry = (bool) $this->option('dry-alert');
        $fail = implode('; ', array_filter([
            $forbiddenWrite ? 'forbidden write' : null,
            $drift ? 'checksum drift' : null,
            $identityBroken ? 'identity broken' : null,
        ]));

        // Heartbeat = «плановая сверка отработала и детерминирована».
        $alerter->heartbeat((string) config('money_recon.ping_url', ''), $ok, $fail, $dry);

        $lines = [];
        if (! $ok) {
            $lines[] = 'FAIL: '.$fail;
        }
        if (($report['new_exceptions'] ?? 0) > 0) {
            $lines[] = 'новые исключения: '.json_encode($report['new_exception_types'], JSON_UNESCAPED_UNICODE);
        }
        // Незавершённость громкая, но не шторм: алерт только при смене статуса/набора источников.
        if (($report['status_changed'] ?? false) && $report['status'] !== 'complete') {
            $lines[] = 'сверка неполная, нет источников: '.implode(', ', $report['missing_sources']);
        }

        if ($lines === []) {
            $alerter->recovered('daily_reconciliation');

            return;
        }
        $lines[] = "день {$report['business_date']}, прогон #".($report['run_id'] ?? '—').'; очередь: php artisan money:recon-exceptions';
        $alerter->alert('daily_reconciliation', 'Денежная сверка P3: требуется внимание', $lines, false, $dry);
    }
}
