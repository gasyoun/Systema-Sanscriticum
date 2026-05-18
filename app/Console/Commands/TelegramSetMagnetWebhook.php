<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketingSetting;
use App\Services\Messaging\TelegramDeliveryChannel;
use Illuminate\Console\Command;

final class TelegramSetMagnetWebhook extends Command
{
    protected $signature = 'telegram:set-magnet-webhook';

    protected $description = 'Регистрирует Telegram lead-magnet webhook по URL из APP_URL';

    public function handle(TelegramDeliveryChannel $telegram): int
    {
        $secret = (string) (MarketingSetting::first()?->tg_webhook_secret ?? '');

        if ($secret === '') {
            $this->error('tg_webhook_secret не задан в MarketingSetting.');

            return self::FAILURE;
        }

        $url = rtrim(config('app.url'), '/').'/api/webhooks/telegram-magnet';

        $this->info("Регистрируем webhook: {$url}");
        $telegram->setWebhook($url, $secret);
        $this->info('✓ Telegram magnet webhook установлен.');

        return self::SUCCESS;
    }
}
