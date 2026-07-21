<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketingSetting;
use App\Services\Messaging\TelegramDeliveryChannel;
use Illuminate\Console\Command;

/**
 * Регистрирует webhook класс-букинг бота @zapisi_ORSbot (Track C, H164) в
 * Telegram: URL /api/webhooks/telegram-zapisi + secret_token из MarketingSetting.
 *
 * Аналог telegram:set-magnet-webhook, но под отдельный zapisi-бот. Middleware
 * VerifyTelegramZapisiWebhook fail-closed по secret_token, поэтому регистрировать
 * вебхук нужно ИМЕННО этим секретом (иначе входящие 403). Privacy mode бота
 * отключается вручную в @BotFather — иначе бот не видит сообщения группы.
 */
final class ZapisiSetWebhook extends Command
{
    protected $signature = 'zapisi:set-webhook';

    protected $description = 'Регистрирует webhook @zapisi_ORSbot в Telegram (URL + secret_token из MarketingSetting)';

    public function handle(TelegramDeliveryChannel $telegram): int
    {
        $settings = MarketingSetting::cached();
        $token = (string) ($settings?->zapisi_bot_token ?? '');
        $secret = (string) ($settings?->zapisi_webhook_secret ?? '');
        $username = (string) ($settings?->zapisi_bot_username ?? '');

        if ($token === '' || $secret === '') {
            $this->error('zapisi_bot_token или zapisi_webhook_secret не заданы в MarketingSetting.');

            return self::FAILURE;
        }

        $url = rtrim((string) config('app.url'), '/').'/api/webhooks/telegram-zapisi';

        $this->info("Регистрируем webhook @zapisi_ORSbot: {$url}");

        // message + channel_post: ProcessTelegramZapisiUpdate читает оба типа
        // (группа шлёт message, канал — channel_post). callback_query не нужен.
        $telegram
            ->usingCredentials($token, $username)
            ->setWebhook($url, $secret, ['message', 'channel_post']);

        $this->info('✓ Webhook @zapisi_ORSbot установлен.');

        return self::SUCCESS;
    }
}
