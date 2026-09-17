<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramBusinessConnection;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportMessage;
use App\Services\TelegramBusiness\TelegramBusinessSender;
use Illuminate\Console\Command;

/**
 * H5065 — состояние полосы Telegram Business одним экраном.
 *
 * Зачем отдельная команда, а не «посмотреть в админке». Три вещи, каждая из
 * которых по отдельности гасит полосу МОЛЧА, и различить их по симптому
 * невозможно: не задан токен/секрет (вебхук 403), не пришло
 * `business_connection` (джоба не может отличить студента от владельца и
 * пропускает сообщения), снят `can_reply` или `auto_reply_enabled` (ответы
 * стоят в очереди и не уходят). Поэтому команда отвечает на все три вопроса
 * сразу и заканчивается ненулевым кодом, если полоса включена, но не способна
 * работать.
 */
final class TelegramBusinessStatus extends Command
{
    protected $signature = 'telegram-business:status';

    protected $description = 'H5065: флаги, токен, подключения и очередь ответов полосы Telegram Business';

    public function handle(TelegramBusinessSender $sender): int
    {
        $enabled = (bool) config('features.telegram_business_bot', false);
        $token = trim((string) config('services.telegram_business.token', '')) !== '';
        $secret = trim((string) config('services.telegram_business.secret', '')) !== '';
        $accountName = (string) config('services.telegram_business.account_name', 'telegram-business');

        $this->table(['параметр', 'значение'], [
            ['features.telegram_business_bot', $enabled ? 'ON' : 'OFF'],
            ['TELEGRAM_BUSINESS_BOT_TOKEN', $token ? 'задан' : 'ПУСТО'],
            ['TELEGRAM_BUSINESS_WEBHOOK_SECRET', $secret ? 'задан' : 'ПУСТО'],
            ['имя аккаунта поддержки', $accountName],
        ]);

        $connections = TelegramBusinessConnection::query()->orderByDesc('id')->get();
        if ($connections->isEmpty()) {
            $this->warn('Подключений нет: Telegram ещё не прислал business_connection. Пока его нет, апдейты business_message пропускаются — по подключению определяется владелец.');
        } else {
            $this->table(
                ['business_connection_id', 'владелец', 'can_reply', 'is_enabled', 'подключено', 'отключено'],
                $connections->map(static fn (TelegramBusinessConnection $c): array => [
                    $c->business_connection_id,
                    (string) $c->owner_telegram_user_id,
                    $c->can_reply ? 'да' : 'НЕТ',
                    $c->is_enabled ? 'да' : 'нет',
                    $c->connected_at?->toDateTimeString() ?? '—',
                    $c->disabled_at?->toDateTimeString() ?? '—',
                ])->all(),
            );
        }

        $account = TelegramSupportAccount::query()->where('name', $accountName)->first();
        if ($account === null) {
            $this->warn("Аккаунта поддержки «{$accountName}» ещё нет: он появится при первом business_message.");
        } else {
            $this->line(sprintf(
                'Аккаунт «%s»: auto_reply_enabled=%s, is_enabled=%s',
                $accountName,
                $account->auto_reply_enabled ? 'да' : 'НЕТ (бот отвечать не будет)',
                $account->is_enabled ? 'да' : 'нет',
            ));

            $pending = TelegramSupportMessage::query()
                ->where('telegram_support_account_id', $account->id)
                ->where('direction', 'outgoing')
                ->where('telegram_message_id', '<', 0)
                ->count();

            $this->line("Ждущих доставки исходящих: {$pending}");
        }

        if (! $enabled) {
            $this->info('Полоса выключена — это штатное состояние по умолчанию.');

            return self::SUCCESS;
        }

        $blockers = [];
        if (! $sender->isConfigured()) {
            $blockers[] = 'не задан TELEGRAM_BUSINESS_BOT_TOKEN';
        }
        if (! $secret) {
            $blockers[] = 'не задан TELEGRAM_BUSINESS_WEBHOOK_SECRET (middleware отвергнет все апдейты)';
        }
        if ($connections->where('is_enabled', true)->where('can_reply', true)->isEmpty()) {
            $blockers[] = 'нет живого подключения с can_reply (проверьте Secretary Mode в @BotFather и право «отвечать» в Business → Чат-боты)';
        }
        if ($account !== null && ! $account->auto_reply_enabled) {
            $blockers[] = "у аккаунта «{$accountName}» снят auto_reply_enabled (H3380-гейт)";
        }

        if ($blockers === []) {
            $this->info('Полоса включена и работоспособна.');

            return self::SUCCESS;
        }

        foreach ($blockers as $blocker) {
            $this->error('Блокер: '.$blocker);
        }

        return self::FAILURE;
    }
}
