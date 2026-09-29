<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Access\TelegramAdminNotifier;
use App\Services\Support\SupportHintsDigestReport;
use Illuminate\Console\Command;

/**
 * H5452: ежедневный дайджест открытых подсказок куратору, 09:00
 * Europe/Moscow. Флаг SUPPORT_HINT_DAILY_DIGEST default OFF и гейтит ТОЛЬКО
 * отправку по расписанию; --dry — ручной прогон, работает и при OFF
 * (паттерн H3392). Расписание — отдельная cron-строка (урок crontab .92:
 * «своя строка = своя судьба»), не внутрь schedule:run.
 */
class SendSupportHintsDigest extends Command
{
    protected $signature = 'support:hints-digest
        {--dry : Только показать дайджест, без отправки в Telegram}
        {--days=30 : Окно поиска открытых подсказок, в днях}';

    protected $description = 'Ежедневный дайджест открытых подсказок куратору 09:00 MSK (H5452).';

    public function handle(SupportHintsDigestReport $report, TelegramAdminNotifier $notifier): int
    {
        $dry = (bool) $this->option('dry');

        if (! $dry && ! (bool) config('features.support_hint_daily_digest', false)) {
            $this->info('support:hints-digest выключен (SUPPORT_HINT_DAILY_DIGEST=false).');

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
            $this->error('Дайджест не доставлен: нет TELEGRAM_BOT_TOKEN / ADMIN_TELEGRAM_ID или API отказал.');

            return self::FAILURE;
        }

        $this->info('Дайджест отправлен: '.implode(', ', $delivered));

        return self::SUCCESS;
    }
}
