<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TelegramBusiness\BusinessSupportReplyDrainer;
use Illuminate\Console\Command;

/**
 * H5065 — досыл ответов полосы Telegram Business.
 *
 * Запускается расписанием каждую минуту как СТРАХОВКА: штатно дренаж вызывает
 * джоба приёма апдейта, сразу после того как автоответ поставил сообщение в
 * очередь. Минутный слот нужен на случай, когда джоба упала между ingest'ом и
 * досылом (или Horizon был недоступен) — иначе ответ остался бы висеть
 * навсегда, а студент ждал бы вечно.
 *
 * Флаг полосы OFF → команда не делает ничего и говорит об этом: молчаливый
 * выход из выключенной полосы читался бы как «дренаж прошёл, доставлять нечего».
 */
final class DrainTelegramBusinessReplies extends Command
{
    protected $signature = 'support:business-drain';

    protected $description = 'H5065: доставляет ждущие ответы полосы Telegram Business от имени аккаунта';

    public function handle(BusinessSupportReplyDrainer $drainer): int
    {
        if (! $drainer->isEnabled()) {
            $this->line('Полоса Telegram Business выключена (TELEGRAM_BUSINESS_BOT_ENABLED=false) — досылать нечего.');

            return self::SUCCESS;
        }

        $stats = $drainer->drain();

        $this->table(
            ['attempted', 'delivered', 'failed', 'skipped'],
            [[$stats['attempted'], $stats['delivered'], $stats['failed'], $stats['skipped']]],
        );

        return self::SUCCESS;
    }
}
