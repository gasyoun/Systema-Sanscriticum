<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Access\TelegramAdminNotifier;
use App\Services\Support\SupportDailyDigest;
use Illuminate\Console\Command;

/**
 * H3242: утренняя сводка вчерашней поддержки на ADMIN_TELEGRAM_ID (gasyoun).
 * Флаг SUPPORT_DAILY_DIGEST default ON — это админская сводка по явной просьбе,
 * не студенческий контур. Выкл: SUPPORT_DAILY_DIGEST=false + config:cache.
 */
class SendSupportDailyDigest extends Command
{
    protected $signature = 'support:daily-digest
        {--dry : Только показать сводку, без отправки в Telegram}
        {--date= : День YYYY-MM-DD (по умолчанию вчера, app tz)}';

    protected $description = 'Вчерашняя сводка поддержки в Telegram админам (H3242).';

    public function handle(SupportDailyDigest $digest, TelegramAdminNotifier $notifier): int
    {
        if (! config('features.support_daily_digest')) {
            $this->info('support:daily-digest выключен (SUPPORT_DAILY_DIGEST=false).');

            return self::SUCCESS;
        }

        $snap = $digest->snapshot($this->option('date') ?: null);

        $this->line($snap['text']);

        if ($this->option('dry')) {
            $this->comment('--dry: Telegram не отправлен.');

            return self::SUCCESS;
        }

        $delivered = $notifier->notifyAdmins($snap['text']);
        if ($delivered === []) {
            $this->error('Сводка не доставлена: нет TELEGRAM_BOT_TOKEN / ADMIN_TELEGRAM_ID или API отказал.');

            return self::FAILURE;
        }

        $this->info('Сводка отправлена: '.implode(', ', $delivered));

        return self::SUCCESS;
    }
}
