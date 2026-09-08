<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketingSetting;
use App\Services\Telegram\SlotNoticeService;
use Illuminate\Console\Command;

/**
 * MG 08-09, слотовые уведомления @zapisi_ORSbot (см. SlotNoticeService):
 *  A. «Сегодня занятия нет» — в обычный слот группы, если занятие перенесено
 *     или датированно отменено, а будущее занятие есть.
 *  B. Оплата за блок — после каждого 4-го занятия блока: до какого числа
 *     ждём оплату и что будет с доступом неоплативших.
 *
 * Дедуп — клеймы TelegramSendGuard внутри сервиса; окно стрельбы 10 минут
 * под каждые-5-минутный планировщик.
 */
class ZapisiSlotNotices extends Command
{
    protected $signature = 'zapisi:slot-notices';

    protected $description = 'Уведомления чатам групп: «сегодня занятия нет» в обычный слот + напоминание об оплате после 4-го занятия блока';

    public function handle(): int
    {
        if (! config('features.telegram_zapisi_bot')) {
            $this->info('Track C (@zapisi_ORSbot) выключен через TELEGRAM_ZAPISI_BOT_ENABLED — пропуск.');

            return self::SUCCESS;
        }

        $settings = MarketingSetting::cached();
        $lead = max(1, (int) ($settings?->zapisi_reminder_lead_minutes ?? 60));

        $service = app(SlotNoticeService::class);
        $noLesson = $service->noLessonToday($lead);
        $payments = $service->blockPaymentReminders();

        $this->info("slot-notices: «сегодня занятия нет» — {$noLesson}, оплата блока — {$payments}.");

        return self::SUCCESS;
    }
}
