<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Access\TelegramAdminNotifier;
use App\Services\Support\AutoReplyWeeklyReport;
use Illuminate\Console\Command;

/**
 * H3392: недельный разбор пробы автоответов H3380 («разбираем что пошло
 * не так») на ADMIN_TELEGRAM_ID. Флаг SUPPORT_AUTO_REPLY_WEEKLY_REPORT
 * default OFF и гейтит ТОЛЬКО отправку по расписанию; --dry — ручной прогон,
 * работает и при OFF (посмотреть цифры до включения флага).
 */
class SendAutoReplyWeeklyReport extends Command
{
    protected $signature = 'support:auto-reply-weekly
        {--dry : Только показать отчёт, без отправки в Telegram}
        {--days=7 : Окно отчёта в днях}';

    protected $description = 'Недельный разбор пробы автоответов H3380 в Telegram админам (H3392).';

    public function handle(AutoReplyWeeklyReport $report, TelegramAdminNotifier $notifier): int
    {
        $dry = (bool) $this->option('dry');

        if (! $dry && ! config('features.support_auto_reply_weekly_report')) {
            $this->info('support:auto-reply-weekly выключен (SUPPORT_AUTO_REPLY_WEEKLY_REPORT=false).');

            return self::SUCCESS;
        }

        $snapshot = $report->build(max(1, (int) $this->option('days')));

        $this->line($snapshot['text']);

        if ($dry) {
            $this->comment('--dry: Telegram не отправлен.');

            return self::SUCCESS;
        }

        $delivered = $notifier->notifyAdmins($snapshot['text']);
        if ($delivered === []) {
            $this->error('Отчёт не доставлен: нет TELEGRAM_BOT_TOKEN / ADMIN_TELEGRAM_ID или API отказал.');

            return self::FAILURE;
        }

        $this->info('Отчёт отправлен: '.implode(', ', $delivered));

        return self::SUCCESS;
    }
}
