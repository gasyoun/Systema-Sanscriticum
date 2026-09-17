<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Messaging\TelegramDeliveryChannel;
use App\Support\TelegramWebhooks;
use Illuminate\Console\Command;

/**
 * H5065 — регистрация вебхука бота полосы Telegram Business.
 *
 * Отличие от прочих бот-вебхуков — в allowed_updates: бизнес-апдейты приходят
 * ТОЛЬКО если они явно перечислены. Без `business_connection` полоса не узнает
 * владельца подключения (а без владельца не может отличить сообщение студента
 * от ответа самого владельца — см. TelegramBusinessNormalizer) и молча
 * пропустит все сообщения; без `business_message` не придёт вообще ничего.
 * Поэтому список здесь не «разумный дефолт», а часть контракта полосы.
 *
 * Секрет берётся из services.telegram_business.secret и обязан совпадать с тем,
 * что проверяет middleware verify.tg.business: пустой секрет = 403 на каждом
 * апдейте, а не «проверка выключена».
 */
final class TelegramBusinessSetWebhook extends Command
{
    protected $signature = 'telegram-business:set-webhook
        {--drop : снять вебхук вместо установки (аварийный откат полосы)}
        {--info : только показать текущее состояние вебхука}';

    protected $description = 'H5065: регистрирует вебхук бота Telegram Business с бизнес-апдейтами (URL + secret_token)';

    public function handle(TelegramDeliveryChannel $telegram): int
    {
        $token = trim((string) config('services.telegram_business.token', ''));
        $secret = trim((string) config('services.telegram_business.secret', ''));
        $username = trim((string) config('services.telegram_business.username', ''));

        if ($token === '') {
            $this->error('TELEGRAM_BUSINESS_BOT_TOKEN не задан — полоса выключена.');

            return self::FAILURE;
        }

        $channel = $telegram->usingCredentials($token, $username);

        if ((bool) $this->option('info')) {
            $info = $channel->getWebhookInfo();
            $this->line((string) json_encode($info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ((bool) $this->option('drop')) {
            $channel->deleteWebhook();
            $this->info('✓ Вебхук Telegram Business снят. Входящие апдейты больше не приходят.');

            return self::SUCCESS;
        }

        if ($secret === '') {
            $this->error('TELEGRAM_BUSINESS_WEBHOOK_SECRET не задан: middleware fail-closed отвергнет все апдейты.');

            return self::FAILURE;
        }

        // Тот же входной узел, что у остальных вебхуков (см. TelegramWebhooks):
        // адрес вебхука нельзя собирать из app.url — разъезд этих адресов уже
        // стоил Track C пяти дней тишины 22-27.07.2026.
        $url = TelegramWebhooks::url('/api/webhooks/telegram-business');

        $this->info("Регистрируем webhook Telegram Business: {$url}");

        $channel->setWebhook(
            $url,
            $secret,
            ['business_connection', 'business_message', 'edited_business_message', 'deleted_business_messages'],
            TelegramWebhooks::certificateContents(),
        );

        $this->info('✓ Webhook установлен (business_connection + business_message + edited + deleted).');

        return self::SUCCESS;
    }
}
