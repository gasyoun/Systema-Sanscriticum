<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LandingPage;
use App\Models\MarketingSetting;
use App\Services\Messaging\TelegramDeliveryChannel;
use App\Support\TelegramWebhooks;
use Illuminate\Console\Command;

final class TelegramSetMagnetWebhook extends Command
{
    protected $signature = 'telegram:set-magnet-webhook {landing? : ID или slug лендинга (свой бот). Без аргумента — глобальный бот из MarketingSetting}';

    protected $description = 'Регистрирует Telegram lead-magnet webhook (глобальный или бота конкретного лендинга)';

    public function handle(TelegramDeliveryChannel $telegram): int
    {
        $landingArg = $this->argument('landing');

        return $landingArg === null
            ? $this->registerGlobal($telegram)
            : $this->registerForLanding($telegram, (string) $landingArg);
    }

    private function registerGlobal(TelegramDeliveryChannel $telegram): int
    {
        $secret = (string) (MarketingSetting::cached()?->tg_webhook_secret ?? '');

        if ($secret === '') {
            $this->error('tg_webhook_secret не задан в MarketingSetting.');

            return self::FAILURE;
        }

        // Общий входной узел, а не app.url — см. App\Support\TelegramWebhooks.
        $url = TelegramWebhooks::url('/api/webhooks/telegram-magnet');

        $this->info("Регистрируем глобальный webhook: {$url}");
        $telegram->setWebhook($url, $secret, ['message'], TelegramWebhooks::certificateContents());
        $this->info('✓ Telegram magnet webhook (глобальный) установлен.');

        return self::SUCCESS;
    }

    private function registerForLanding(TelegramDeliveryChannel $telegram, string $landingArg): int
    {
        $landing = LandingPage::with('bot')
            ->where('id', $landingArg)
            ->orWhere('slug', $landingArg)
            ->first();

        if (! $landing) {
            $this->error("Лендинг не найден: {$landingArg}");

            return self::FAILURE;
        }

        $bot = $landing->bot;

        if (! $bot || empty($bot->tg_bot_token) || empty($bot->tg_webhook_secret)) {
            $this->error("У лендинга «{$landing->title}» не заполнены token/secret бота.");

            return self::FAILURE;
        }

        $url = $bot->webhookUrl();

        $this->info("Регистрируем webhook бота лендинга «{$landing->title}»: {$url}");

        // callback_query обязателен — в n8n-анкете есть inline-кнопки.
        $telegram
            ->usingCredentials($bot->tg_bot_token, $bot->tg_bot_username)
            ->setWebhook($url, (string) $bot->tg_webhook_secret, ['message', 'callback_query'], TelegramWebhooks::certificateContents());

        $this->info('✓ Webhook бота лендинга установлен.');

        return self::SUCCESS;
    }
}
