<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * H5709 — суточное доказательство живости вебхук-полосы Telegram Business.
 *
 * Coverage v2 недельной аналитики вопросов требует строку доказательства на
 * каждый день окна для КАЖДОГО включённого источника. MTProto-аккаунт
 * (support) пишет их сам на каждом живом скане; вебхук-полоса по MTProto не
 * ходит, поэтому её дневное доказательство — успешный проб Bot API
 * getWebhookInfo: сеть ответила, вебхук на месте, ошибок доставки за сутки
 * нет. Провал проба день НЕ записывает: тихий день остаётся неизвестным
 * охватом (H5768), а причина остаётся на строке аккаунта как sync_error.
 */
final class TelegramBusinessDailyEvidence extends Command
{
    protected $signature = 'telegram-business:daily-evidence';

    protected $description = 'H5709: суточный проб живости вебхук-полосы Telegram Business (getWebhookInfo) и запись дневного доказательства охвата';

    public function handle(TelegramSupportSyncService $sync): int
    {
        if (! (bool) config('features.telegram_business_bot', false)) {
            $this->info('Полоса Telegram Business выключена — доказательство не требуется.');

            return self::SUCCESS;
        }

        $accountName = (string) config('services.telegram_business.account_name', 'telegram-business');

        $reason = $this->probeWebhook();
        if ($reason !== null) {
            $sync->recordExternalSourceFailure($accountName, 'daily-evidence: '.$reason);
            $this->error('Полоса не доказана: '.$reason);

            return self::FAILURE;
        }

        $sync->recordExternalSourceSuccess($accountName);
        $this->info('Вебхук-полоса жива — дневное доказательство охвата записано.');

        return self::SUCCESS;
    }

    /**
     * @return string|null null = полоса доказана; строка = причина провала
     */
    private function probeWebhook(): ?string
    {
        $token = trim((string) config('services.telegram_business.token', ''));
        if ($token === '') {
            return 'TELEGRAM_BUSINESS_BOT_TOKEN не задан';
        }

        $timeout = max(3, (int) config('services.telegram_business.timeout_seconds', 15));

        try {
            $response = Http::timeout($timeout)->get(
                'https://api.telegram.org/bot'.$token.'/getWebhookInfo'
            );
        } catch (ConnectionException $e) {
            return 'transport: '.mb_substr($e->getMessage(), 0, 200);
        }

        if ($response->failed()) {
            return 'http '.$response->status();
        }

        $url = trim((string) $response->json('result.url', ''));
        if ($url === '') {
            return 'webhook url пуст (telegram-business:set-webhook не выполнялся)';
        }

        // Ошибка доставки за последние сутки = полоса прямо сейчас роняет
        // апдейты: доказательством охвата это быть не может.
        $lastErrorAt = (int) $response->json('result.last_error_date', 0);
        if ($lastErrorAt > 0 && $lastErrorAt > time() - 86400) {
            return 'webhook delivery error за последние 24ч';
        }

        return null;
    }
}
