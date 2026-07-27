<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\LandingBot;
use App\Models\MarketingSetting;
use App\Support\TelegramWebhooks;

/**
 * Полный список Telegram-ботов, у которых есть вебхук на этом приложении.
 *
 * Раньше такого списка не существовало — он жил в памяти оператора, и переезд
 * входного узла 22.07.2026 забыл @zapisi_ORSbot на пять дней. Теперь любой
 * переезд — это одна переменная окружения и одна команда (`telegram:webhooks --set`).
 *
 * Max-бот сюда НЕ входит сознательно: это другой мессенджер, до прода он
 * достукивается напрямую и остаётся на app.url (см. MaxSetMagnetWebhook).
 */
class TelegramWebhookRegistry
{
    /**
     * @return list<TelegramBotWebhook>
     */
    public function all(): array
    {
        $settings = MarketingSetting::cached();

        return array_values(array_filter([
            $this->cabinet(),
            $this->magnet($settings),
            $this->zapisi($settings),
            ...$this->landingBots(),
        ]));
    }

    /**
     * Бот студенческого кабинета. Живёт в .env (не в MarketingSetting) и до сих
     * пор регистрировался вручную через curl — то есть был единственным ботом
     * полностью вне кода.
     */
    private function cabinet(): TelegramBotWebhook
    {
        $token = (string) (config('services.telegram.student_bot_token') ?: config('services.telegram.bot_token'));
        $username = config('services.telegram.student_bot_username') ?: config('services.telegram.bot_username');
        $secret = (string) config('services.telegram.bot_webhook_secret');

        return new TelegramBotWebhook(
            key: 'cabinet',
            label: 'Кабинет (привязка, ИИ-куратор)',
            username: $username ? (string) $username : null,
            token: $token,
            secret: $secret,
            url: TelegramWebhooks::url('/api/telegram/webhook'),
            // Контроллер разбирает message и callback_query (кнопка
            // «Разблокировать»), больше ничего — сужаем набор осознанно.
            allowedUpdates: ['message', 'callback_query'],
            problem: $this->problemFor($token, $secret, 'STUDENT_TELEGRAM_BOT_TOKEN/TELEGRAM_BOT_TOKEN', 'TELEGRAM_BOT_WEBHOOK_SECRET'),
        );
    }

    private function magnet(?MarketingSetting $settings): TelegramBotWebhook
    {
        $token = (string) ($settings?->tg_bot_token ?? '');
        $secret = (string) ($settings?->tg_webhook_secret ?? '');

        return new TelegramBotWebhook(
            key: 'magnet',
            label: 'Лид-магнит (глобальный)',
            username: $settings?->tg_bot_username,
            token: $token,
            secret: $secret,
            url: TelegramWebhooks::url('/api/webhooks/telegram-magnet'),
            allowedUpdates: ['message'],
            problem: $this->problemFor($token, $secret, 'MarketingSetting.tg_bot_token', 'MarketingSetting.tg_webhook_secret'),
        );
    }

    private function zapisi(?MarketingSetting $settings): TelegramBotWebhook
    {
        $token = (string) ($settings?->zapisi_bot_token ?? '');
        $secret = (string) ($settings?->zapisi_webhook_secret ?? '');

        return new TelegramBotWebhook(
            key: 'zapisi',
            label: 'Записи (@zapisi_ORSbot)',
            username: $settings?->zapisi_bot_username,
            token: $token,
            secret: $secret,
            url: TelegramWebhooks::url('/api/webhooks/telegram-zapisi'),
            // Группа шлёт message, канал — channel_post (как в zapisi:set-webhook).
            allowedUpdates: ['message', 'channel_post'],
            problem: $this->problemFor($token, $secret, 'MarketingSetting.zapisi_bot_token', 'MarketingSetting.zapisi_webhook_secret'),
        );
    }

    /**
     * @return list<TelegramBotWebhook>
     */
    private function landingBots(): array
    {
        if (! class_exists(LandingBot::class)) {
            return [];
        }

        return LandingBot::query()
            ->get()
            // Тот же критерий готовности, что и у остальной магнит-логики.
            ->filter(fn (LandingBot $bot): bool => $bot->isUsable())
            ->map(function (LandingBot $bot): TelegramBotWebhook {
                $secret = (string) ($bot->tg_webhook_secret ?? '');

                return new TelegramBotWebhook(
                    key: 'landing:'.$bot->webhook_key,
                    label: 'Лендинг-бот @'.$bot->tg_bot_username,
                    username: $bot->tg_bot_username,
                    token: (string) $bot->tg_bot_token,
                    secret: $secret,
                    url: $bot->webhookUrl(),
                    allowedUpdates: ['message', 'callback_query'],
                    problem: $this->problemFor((string) $bot->tg_bot_token, $secret, 'LandingBot.tg_bot_token', 'LandingBot.tg_webhook_secret'),
                );
            })
            ->values()
            ->all();
    }

    private function problemFor(string $token, string $secret, string $tokenSource, string $secretSource): ?string
    {
        if ($token === '') {
            return "не задан токен ({$tokenSource})";
        }

        if ($secret === '') {
            return "не задан секрет ({$secretSource}) — вебхук отвечал бы 403 на каждый апдейт";
        }

        return null;
    }
}
