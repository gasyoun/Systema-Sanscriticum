<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reactivation\ReactivationWaveCohort;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * H5288 — сухой прогон реактивационной волны A. Строит когорту
 * ({@see ReactivationWaveCohort}) и пишет ДВА файла под storage/app/reactivation:
 *
 *  • wave-a-dry-run-YYYY-MM-DD.md — отчёт для MG: сегменты, разбивка по каналам
 *    (бот Telegram / email), все исключения. Без персональных данных.
 *  • wave-a-sendlist-YYYY-MM-DD.csv — персональный список рассылки (PII; живёт
 *    в storage, в git не попадает) для волны B, которая и будет отправлять.
 *
 * НИЧЕГО НЕ ОТПРАВЛЯЕТ: ни Mail, ни Telegram, ни очередей — только чтение БД
 * и запись файлов. Отправка — волна B (H5294) после явного «да» MG на этот отчёт.
 */
final class ReactivationWaveADryRun extends Command
{
    protected $signature = 'reactivation:wave-a-dry-run {--no-write : только вывести сводку, файлы не писать}';

    protected $description = 'Reactivation wave A dry run: cohort counts + channel split report, sends NOTHING';

    public function handle(ReactivationWaveCohort $cohort): int
    {
        $startedAt = microtime(true);
        $built = $cohort->build();
        $counts = $built['counts'];

        $this->table(['metric', 'value'], [
            ['non_continuers (не продлили блок)', $counts['non_continuer']],
            ['lapsed pool (без действующей группы)', $counts['lapsed']],
            ['total cohort', $counts['total']],
            ['channel: telegram bot', $counts['channel_'.ReactivationWaveCohort::CHANNEL_TELEGRAM_BOT]],
            ['channel: email fallback', $counts['channel_'.ReactivationWaveCohort::CHANNEL_EMAIL]],
            ['channel: none (нет канала)', $counts['channel_'.ReactivationWaveCohort::CHANNEL_NONE]],
            ['excluded: active payers', $counts['excluded_active_payers']],
            ['excluded: refund-only', $counts['excluded_refund_only']],
            ['excluded: expelled/left everywhere', $counts['excluded_expelled_or_left']],
            ['opt-out messenger (TG есть, согласия нет)', $counts['opt_out_messenger']],
            ['opt-out/suppressed email', $counts['opt_out_or_suppressed_email']],
            ['outbound messages', 0],
        ]);

        if ($this->option('no-write')) {
            $this->line('--no-write: файлы не писались.');

            return self::SUCCESS;
        }

        $dir = storage_path('app/reactivation');
        @mkdir($dir, 0775, true);
        $date = Carbon::now()->format('Y-m-d');

        $reportPath = $dir.'/wave-a-dry-run-'.$date.'.md';
        file_put_contents($reportPath, $this->reportMarkdown($counts, $date, $built['rows']->count()));

        $listPath = $dir.'/wave-a-sendlist-'.Carbon::now()->format('Ymd').'.csv';
        $handle = fopen($listPath, 'w');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM: Excel открывает кириллицу.
        fputcsv($handle, ['user_id', 'name', 'segment', 'channel', 'tg_ok', 'email_ok', 'last_course'], ',', '"', '\\');
        foreach ($built['rows'] as $row) {
            fputcsv($handle, [
                $row['user_id'],
                $row['name'],
                $row['segment'],
                $row['channel'],
                $row['tg_ok'] ? '1' : '0',
                $row['email_ok'] ? '1' : '0',
                $row['last_course'],
            ], ',', '"', '\\');
        }
        fclose($handle);

        $elapsed = (int) round((microtime(true) - $startedAt) * 1000);
        $this->line("report: {$reportPath}");
        $this->line("sendlist (PII, storage): {$listPath}");
        $this->line("elapsed: {$elapsed} ms · outbound messages: 0");

        return self::SUCCESS;
    }

    /** @param array<string, int> $counts */
    private function reportMarkdown(array $counts, string $date, int $rowCount): string
    {
        $tg = $counts['channel_'.ReactivationWaveCohort::CHANNEL_TELEGRAM_BOT];
        $email = $counts['channel_'.ReactivationWaveCohort::CHANNEL_EMAIL];
        $none = $counts['channel_'.ReactivationWaveCohort::CHANNEL_NONE];

        return <<<MD
        # Reactivation wave A — dry-run report ({$date})

        Cohort (H5288; sources: BUSINESS_HEALTH_ASSESSMENT_2026-09-14 — 563 lapsed + 919 non-continuers; CENSUS_REACTIVATION_OUTCOME_23-08-2026).

        ## Segments

        | Segment | People |
        |---|---|
        | Non-continuers (paid, next block unbought) | {$counts['non_continuer']} |
        | Lapsed pool (payer, no active/forming group) | {$counts['lapsed']} |
        | **Total** | **{$counts['total']}** |

        ## Channel split (primary: TG bot where chat id + consent, else email)

        | Channel | People |
        |---|---|
        | Telegram bot | {$tg} |
        | Email fallback | {$email} |
        | No channel available | {$none} |

        Per segment: non-continuers tg {$counts['non_continuer_channel_tg_bot']} / email {$counts['non_continuer_channel_email']} / none {$counts['non_continuer_channel_none']}; lapsed tg {$counts['lapsed_channel_tg_bot']} / email {$counts['lapsed_channel_email']} / none {$counts['lapsed_channel_none']}.

        ## Exclusions

        | Exclusion | People |
        |---|---|
        | Active payers (in forming/active group) | {$counts['excluded_active_payers']} |
        | Refund-only (no real paid payment) | {$counts['excluded_refund_only']} |
        | Expelled/left on every course | {$counts['excluded_expelled_or_left']} |
        | Messenger opt-out (TG linked, no consent) | {$counts['opt_out_messenger']} |
        | Email opt-out or suppressed | {$counts['opt_out_or_suppressed_email']} |

        Send list rows: {$rowCount} (CSV in storage/app/reactivation, PII, gitignored — wave B input).

        **Outbound messages sent by this dry run: 0.** The send itself waits for MG's explicit go (wave B, H5294).
        MD;
    }
}
