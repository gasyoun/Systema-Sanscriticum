<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 152-ФЗ ст. 5 ч. 7: ПДн не хранятся дольше, чем требуют цели.
 *
 *  - обнуляет IP-адреса старше config('privacy.ip_retention_days') в журналах
 *    активности (config('privacy.ip_tables')); строки остаются, без IP;
 *  - --report: перечисляет забытые резервные копии таблиц (*_bkp_*) с ПДн —
 *    удалять их только вручную после проверки (команда их не трогает).
 *
 * Без --apply — только подсчёт. Плановый запуск (--scheduled) — no-op, пока
 * features.privacy_prune выключен (дефолт OFF до утверждения сроков).
 */
class PrivacyPrune extends Command
{
    protected $signature = 'privacy:prune
        {--apply : обнулить IP (без флага — только подсчёт)}
        {--scheduled : плановый запуск — работает только при features.privacy_prune}
        {--report : показать забытые *_bkp_* таблицы}';

    protected $description = '152-ФЗ: обнулить устаревшие IP в журналах активности; отчёт о резервных таблицах с ПДн';

    public function handle(): int
    {
        if ($this->option('scheduled') && ! config('features.privacy_prune')) {
            $this->comment('features.privacy_prune OFF — плановая очистка не выполняется.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply') || (bool) $this->option('scheduled');
        $days = max(1, (int) config('privacy.ip_retention_days', 180));
        $cutoff = now()->subDays($days);

        $this->info(($apply ? 'Очистка' : 'Сухой прогон').": IP старше {$days} дн. (до {$cutoff->toDateString()})");

        $total = 0;
        foreach ((array) config('privacy.ip_tables', []) as $table => [$ipColumn, $dateColumn]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $ipColumn) || ! Schema::hasColumn($table, $dateColumn)) {
                $this->line("  {$table}: нет таблицы/колонок — пропуск");

                continue;
            }

            if (! $this->isNullable($table, $ipColumn)) {
                $this->warn("  {$table}.{$ipColumn}: колонка NOT NULL — пропуск (нужна миграция)");

                continue;
            }

            $query = DB::table($table)->whereNotNull($ipColumn)->where($dateColumn, '<', $cutoff);
            $count = (clone $query)->count();
            $total += $count;

            if ($apply && $count > 0) {
                $query->update([$ipColumn => null]);
            }

            $this->line("  {$table}.{$ipColumn}: {$count}");
        }

        $this->info(($apply ? 'Обнулено' : 'К обнулению').": {$total}");
        if ($apply) {
            Log::info('privacy:prune', ['days' => $days, 'nulled' => $total]);
        }

        if ($this->option('report')) {
            $this->reportBackupTables();
        }

        return self::SUCCESS;
    }

    private function isNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $col) {
            if (($col['name'] ?? null) === $column) {
                return (bool) ($col['nullable'] ?? false);
            }
        }

        return false;
    }

    private function reportBackupTables(): void
    {
        $tables = array_filter(
            array_map(fn ($t) => is_array($t) ? ($t['name'] ?? '') : (string) $t, Schema::getTables()),
            fn (string $name) => preg_match('/_bkp_|_backup_|_bak_/i', $name) === 1,
        );

        if ($tables === []) {
            $this->info('Резервных *_bkp_* таблиц нет.');

            return;
        }

        $this->warn('Резервные таблицы (возможны ПДн; удалять вручную после проверки):');
        foreach ($tables as $name) {
            $this->line("  {$name}: ".DB::table($name)->count().' строк');
        }
    }
}
