<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Повесить вебхук бота календаря (CONTENT_CAL_TG_BOT_TOKEN) на
 * /api/webhooks/content-calendar-tg, только channel_post. У бота один вебхук
 * на токен: если он уже смотрит в другое место — отказ без --force, чтобы не
 * отобрать вебхук у чужой полосы (урок tg-fanout, 14-10-2026).
 */
class ContentCalendarTelegramWebhookCommand extends Command
{
    protected $signature = 'content:tg-webhook {--force : перезаписать чужой вебхук} {--info : только показать текущий}';

    protected $description = 'Register the content-calendar bot webhook (channel_post ingest)';

    public function handle(): int
    {
        $token = (string) config('content_calendar.tg_bot_token');
        $secret = (string) config('content_calendar.tg_webhook_secret');
        if ($token === '' || $secret === '') {
            $this->error('Нужны CONTENT_CAL_TG_BOT_TOKEN и CONTENT_CAL_TG_WEBHOOK_SECRET.');

            return self::FAILURE;
        }

        $api = "https://api.telegram.org/bot{$token}";
        $target = route('webhook.content-calendar-tg');
        $current = (string) Http::timeout(15)->get($api.'/getWebhookInfo')->json('result.url');
        $this->line('Текущий вебхук: '.($current !== '' ? $current : '(нет)'));
        if ($this->option('info')) {
            return self::SUCCESS;
        }

        if ($current !== '' && $current !== $target && ! $this->option('force')) {
            $this->error("Вебхук бота уже смотрит в {$current} — это чужая полоса? Отдельный бот для календаря или --force.");

            return self::FAILURE;
        }

        $response = Http::timeout(15)->asForm()->post($api.'/setWebhook', [
            'url' => $target,
            'secret_token' => $secret,
            'allowed_updates' => json_encode(['channel_post']),
        ]);
        if ($response->json('ok') !== true) {
            $this->error('setWebhook: '.($response->json('description') ?? 'HTTP '.$response->status()));

            return self::FAILURE;
        }

        $this->info("Вебхук установлен: {$target} (channel_post).");

        return self::SUCCESS;
    }
}
