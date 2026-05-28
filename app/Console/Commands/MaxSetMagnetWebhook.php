<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketingSetting;
use App\Services\Messaging\MaxDeliveryChannel;
use Illuminate\Console\Command;

final class MaxSetMagnetWebhook extends Command
{
    protected $signature = 'max:set-magnet-webhook';

    protected $description = 'Регистрирует Max Bot lead-magnet webhook по URL из APP_URL';

    public function handle(MaxDeliveryChannel $max): int
    {
        $secret = (string) (MarketingSetting::cached()?->max_webhook_secret ?? '');

        if ($secret === '') {
            $this->error('max_webhook_secret не задан в MarketingSetting.');

            return self::FAILURE;
        }

        $url = rtrim(config('app.url'), '/')."/api/webhooks/max-magnet/{$secret}";

        $this->info("Регистрируем webhook: {$url}");
        $max->setWebhook($url);
        $this->info('✓ Max magnet webhook установлен.');

        return self::SUCCESS;
    }
}
