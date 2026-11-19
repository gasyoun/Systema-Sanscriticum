<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Messaging\TelegramDeliveryChannel;
use App\Services\Telegram\MgDecideBotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Приём апдейтов контрол-бота MG (H6452) long polling'ом — зеркало
 * {@see TelegramPollStudentUpdates}, но со своим токеном (MG_DECIDE_BOT_TOKEN)
 * и без реинжекции: обработка — сразу в {@see MgDecideBotService}, своего
 * вебхук-контроллера у этого бота нет. Токен должен принадлежать ОТДЕЛЬНОМУ
 * боту: @samskrte_ops_bot занят business-вебхуком (getWebhookInfo,
 * проба 11-10-2026), личный аккаунт запрещён правилом D1.
 *
 * Демон: выходит по poll_max_lifetime_seconds с кодом 0 — supervisor поднимает
 * свежий процесс (та же логика, что у telegram-student-poll и Horizon).
 */
class TelegramPollMgDecideUpdates extends Command
{
    protected $signature = 'telegram:poll-mg-decide
        {--once : Один заход getUpdates и выход (для проверки/тестов)}
        {--max-seconds= : Сколько секунд жить перед плановым выходом (по умолчанию из конфига)}
        {--release-webhook : Снять зарегистрированный вебхук и забирать апдейты поллингом}
        {--fake-update= : JSON одного апдейта или массива — смоук: обработать и выйти, без getUpdates и токена}';

    protected $description = 'Long-poll контрол-бота MG: /decide рендерит карточки @DECIDE, тап вердикта пишет pack в vote-inbox (H6452).';

    /** Ключ курсора: update_id, с которого продолжаем. Подтверждает прием Telegram'у. */
    private const OFFSET_KEY = 'telegram:mg-decide:poll:offset';

    private bool $shouldStop = false;

    public function handle(): int
    {
        if ($fake = (string) $this->option('fake-update')) {
            return $this->runFakeUpdate($fake);
        }

        if (! config('mg_decide.poll_enabled', false)) {
            $this->info('Поллинг контрол-бота MG выключен (MG_DECIDE_POLL_ENABLED). Для смоука есть --fake-update.');

            return self::SUCCESS;
        }

        $token = (string) config('mg_decide.bot_token');
        if ($token === '') {
            $this->error('Нужен MG_DECIDE_BOT_TOKEN (отдельный бот из BotFather; ops-токен занят business-вебхуком, личный аккаунт запрещён D1).');

            return self::FAILURE;
        }

        $client = app(TelegramDeliveryChannel::class)
            ->usingCredentials($token, (string) config('mg_decide.bot_username'));

        try {
            $this->releaseWebhook($client);
        } catch (Throwable $e) {
            $this->error('Не удалось освободить дорожку апдейтов: '.$e->getMessage());

            return self::FAILURE;
        }

        $pollTimeout = (int) config('mg_decide.poll_timeout_seconds', 50);
        $deadline = $this->option('once')
            ? null
            : now()->addSeconds($this->maxLifetimeSeconds())->getTimestamp();

        $this->trapTermination();

        $handled = 0;
        do {
            try {
                $handled += $this->pollOnce($client, $pollTimeout);
            } catch (Throwable $e) {
                Log::error('MgDecide poll: getUpdates failed', ['error' => $e->getMessage()]);
                $this->error('getUpdates: '.$e->getMessage());

                sleep((int) config('mg_decide.poll_retry_seconds', 10));
            }
        } while (! $this->option('once') && ! $this->shouldStop && time() < $deadline);

        $this->info("MgDecide poll: обработано апдейтов — {$handled}.");

        return self::SUCCESS;
    }

    /**
     * Смоук без сети и токена (паттерн fake-mic H6317): фейковые апдейты идут
     * прямо в сервис; вердикт-апдейт реально пишет pack в vote-inbox.
     */
    private function runFakeUpdate(string $json): int
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $this->error('--fake-update: не JSON-объект/массив');

            return self::FAILURE;
        }

        $updates = isset($decoded[0]) || array_is_list($decoded) && $decoded !== [] ? $decoded : [$decoded];
        $service = app(MgDecideBotService::class);

        foreach ($updates as $update) {
            if (! is_array($update)) {
                continue;
            }
            $consumed = $service->handleUpdate($update);
            $kind = isset($update['callback_query']) ? 'callback' : (isset($update['message']['text']) ? (string) $update['message']['text'] : '?');
            $this->line(sprintf('[fake] %s -> consumed=%s', $kind, $consumed ? 'yes' : 'no'));
        }

        return self::SUCCESS;
    }

    /**
     * Один заход long polling. Возвращает число обработанных апдейтов.
     * Курсор двигается по каждому апдейту после обработки: сервис глотает
     * свои ошибки, зацикливание на отравленном апдейте исключено.
     */
    private function pollOnce($client, int $pollTimeout): int
    {
        $offset = (int) Cache::get(self::OFFSET_KEY, 0);

        $updates = $client->getUpdates($offset, $pollTimeout, ['message', 'callback_query']);

        $service = app(MgDecideBotService::class);

        foreach ($updates as $update) {
            if (! is_array($update) || ! isset($update['update_id'])) {
                continue;
            }

            $service->handleUpdate($update);
            Cache::forever(self::OFFSET_KEY, ((int) $update['update_id']) + 1);
        }

        return count($updates);
    }

    /**
     * Освобождает вебхук — но ТОЛЬКО по явному флагу (урок telegram:poll-student:
     * молчаливое снятие уводило бота с рабочей дорожки). Пока вебхук
     * зарегистрирован, getUpdates отвечает 409 Conflict.
     */
    private function releaseWebhook($client): void
    {
        $url = (string) ($client->getWebhookInfo()['url'] ?? '');

        if ($url === '') {
            return;
        }

        if (! $this->option('release-webhook')) {
            throw new RuntimeException(
                "У бота зарегистрирован вебхук ({$url}) — апдейты идут через него. "
                .'Поллинг снял бы его и переключил дорожку. Если это и нужно, запустите с --release-webhook.'
            );
        }

        $client->deleteWebhook();

        Log::warning('MgDecide poll: webhook removed in favour of long polling', ['was' => $url]);
        $this->warn("Вебхук снят ({$url}) — апдейты теперь забирает long polling.");
    }

    private function maxLifetimeSeconds(): int
    {
        $option = $this->option('max-seconds');

        return $option !== null
            ? max(1, (int) $option)
            : (int) config('mg_decide.poll_max_lifetime_seconds', 3600);
    }

    /**
     * SIGTERM от supervisor'а должен дать дожить текущему заходу, а не рвать
     * процесс посреди getUpdates: иначе принятые, но не подтвержденные апдейты
     * придут повторно.
     */
    private function trapTermination(): void
    {
        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function () use ($signal): void {
                $this->shouldStop = true;

                Log::info('MgDecide poll: termination signal, finishing current cycle', ['signal' => $signal]);
            });
        }
    }
}
