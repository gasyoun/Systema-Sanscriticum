<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Access\TelegramAdminNotifier;
use App\Services\Telegram\MadelineSyncBreaker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Канал крика общего предохранителя (sentinel breaker) на .92 — H4691.
 *
 * Библиотека `sentinel_breaker.py` на каждой заморозке и авто-разморозке зовёт
 * SENTINEL_BREAKER_ALARM_CMD, дописывая "<label>" "<message>". В приложении эта
 * команда и есть такой ALARM_CMD (см. {@see MadelineSyncBreaker}):
 * сообщение уходит в критический чат cabinet:probe (тот же, куда падают «Кабинет
 * лежит»), а если он не настроен — админам бота. Лог пишется всегда: это
 * durable-след, Telegram — доставка. Код выхода всегда 0 — упавший крик не
 * должен ломать стража, который его вызвал.
 */
class SentinelBreakerAlarm extends Command
{
    protected $signature = 'guards:breaker-alarm
        {label : Имя стража (как его назвала библиотека)}
        {body : Текст крика}';

    protected $description = 'Deliver a sentinel-breaker freeze/unfreeze alarm to Telegram (H4691).';

    public function handle(TelegramAdminNotifier $notifier): int
    {
        $label = (string) $this->argument('label');
        $body = (string) $this->argument('body');
        $text = "🧯 Предохранитель «{$label}»: {$body}";

        Log::critical('sentinel breaker alarm', ['label' => $label, 'body' => $body]);

        $chatIds = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('cabinet_probe.telegram_chat_id', '')),
        )));
        $delivered = $chatIds !== []
            ? $notifier->notifyRecipients($chatIds, $text)
            : $notifier->notifyAdmins($text);

        $this->line('delivered='.count($delivered));

        return self::SUCCESS;
    }
}
