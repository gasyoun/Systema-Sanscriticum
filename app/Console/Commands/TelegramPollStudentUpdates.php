<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Аварийный приём апдейтов кабинетного (студенческого) бота long polling'ом
 * вместо вебхука — зеркало {@see PollTelegramZapisiUpdates} для дорожки
 * /api/telegram/webhook.
 *
 * Зачем не вебхук. Инцидент 06-09-2026 (reports/INCIDENT_STUDENT_BOT_WEBHOOK_DEAD_06-09-2026.md,
 * Uprava): входящие POST-ы Telegram к 193.232.229.92 дают «Connection timed out»
 * с ~21-08-2026 — привязки встали, /вход молчит, nginx не видит ни одного
 * POST-а. Исходящий канал при этом работает (getWebhookInfo/getUpdates ходят).
 * Тот же сценарий уже решался для @zapisi_ORSbot 27-07-2026 (там — tg-tunnel).
 *
 * Реинжекция: каждый апдейт POST-ится локально на родной
 * `POST /api/telegram/webhook` с тем же секретом
 * (X-Telegram-Bot-Api-Secret-Token), что ставит Telegram, — контроллер,
 * middleware и вся логика остаются единственной дорожкой, ничего не
 * дублируется. Курсор двигается ПОСЛЕ успешной реинжекции и по одному
 * апдейту: падение процесса стоит максимум одного повторного апдейта.
 *
 * Демон: выходит по `poll_max_lifetime_seconds` с кодом 0 — supervisor
 * поднимает свежий процесс, чтобы после деплоя поллер не оставался на
 * старом коде (та же логика, что у рестарта Horizon в deploy.sh).
 */
class TelegramPollStudentUpdates extends Command
{
    protected $signature = 'telegram:poll-student
        {--once : Один заход getUpdates и выход (для проверки/тестов)}
        {--max-seconds= : Сколько секунд жить перед плановым выходом (по умолчанию из конфига)}
        {--release-webhook : Снять зарегистрированный вебхук и забирать апдейты поллингом}';

    protected $description = 'АВАРИЙНЫЙ приём апдейтов кабинетного бота поллингом — когда входной узел вебхуков недоступен (инцидент 06-09-2026). Штатно апдейты приходят вебхуком.';

    /** Ключ курсора: update_id, с которого продолжаем. Подтверждает приём Telegram'у. */
    private const OFFSET_KEY = 'telegram:student:poll:offset';

    private bool $shouldStop = false;

    public function handle(): int
    {
        // Свой рубильник: поллинг — аварийный режим, штатно апдейты приходят
        // вебхуком. Дефолт false, чтобы случайный запуск не уводил бота с
        // вебхука молча (урок zapisi:poll).
        if (! config('services.telegram_student.poll_enabled', false)) {
            $this->info('Аварийный поллинг кабинетного бота выключен (TELEGRAM_STUDENT_POLL_ENABLED) — штатно апдейты забирает вебхук.');

            return self::SUCCESS;
        }

        $token = (string) (config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token'));
        $secret = (string) config('services.telegram.bot_webhook_secret');
        if ($token === '' || $secret === '') {
            $this->error('Нужны STUDENT_TELEGRAM_BOT_TOKEN (или TELEGRAM_BOT_TOKEN) и TELEGRAM_BOT_WEBHOOK_SECRET — поллить/реинжектировать нечем.');

            return self::FAILURE;
        }

        $client = app(\App\Services\Messaging\TelegramDeliveryChannel::class)
            ->usingCredentials($token, (string) config('services.telegram.student_bot_username'));

        // Пока вебхук зарегистрирован, getUpdates отвечает 409 Conflict: Telegram
        // отдаёт апдейты ровно одним способом. Снимаем явно и однократно.
        try {
            $this->releaseWebhook($client);
        } catch (Throwable $e) {
            $this->error('Не удалось снять вебхук: '.$e->getMessage());

            return self::FAILURE;
        }

        $pollTimeout = (int) config('services.telegram_student.poll_timeout_seconds', 50);
        $deadline = $this->option('once')
            ? null
            : now()->addSeconds($this->maxLifetimeSeconds())->getTimestamp();

        $this->trapTermination();

        $handled = 0;
        do {
            try {
                $handled += $this->pollOnce($client, $pollTimeout, $secret);
            } catch (Throwable $e) {
                Log::error('Student poll: getUpdates failed', ['error' => $e->getMessage()]);
                $this->error('getUpdates: '.$e->getMessage());

                sleep((int) config('services.telegram_student.poll_retry_seconds', 10));
            }
        } while (! $this->option('once') && ! $this->shouldStop && time() < $deadline);

        $this->info("Student poll: обработано апдейтов — {$handled}.");

        return self::SUCCESS;
    }

    /**
     * Один заход long polling. Возвращает число обработанных апдейтов.
     */
    private function pollOnce($client, int $pollTimeout, string $secret): int
    {
        $offset = (int) Cache::get(self::OFFSET_KEY, 0);

        // allowed_updates те же, что регистрирует вебхук реестра
        // (TelegramWebhookRegistry: ['message', 'callback_query']).
        $updates = $client->getUpdates($offset, $pollTimeout, ['message', 'callback_query']);

        // Реинжекция — сразу в САМО приложение (app.url), НЕ через входной узел
        // TelegramWebhooks::url(): тот отдаёт TELEGRAM_WEBHOOK_BASE_URL с
        // self-signed сертификатом и мёртвым туннелем — это как раз сломанная
        // дорожка, из-за которой поллинг и заведён. Локальный путь app → nginx →
        // приложение здоров (405/65ms, 200/0.35s, 06-09).
        $url = rtrim((string) config('app.url'), '/').'/api/telegram/webhook';

        foreach ($updates as $update) {
            if (! is_array($update) || ! isset($update['update_id'])) {
                continue;
            }

            // Реинжекция в родной пайплайн. Не 2xx (в т.ч. 5xx приложения) —
            // курсор не двигаем и выходим из пачки: Telegram отдаст апдейт
            // повторно со следующим заходом getUpdates.
            $response = Http::withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $secret])
                ->timeout(30)
                ->post($url, $update);

            if (! $response->successful()) {
                Log::error('Student poll: webhook re-injection failed', [
                    'update_id' => $update['update_id'],
                    'status' => $response->status(),
                    'url' => $url,
                ]);
                $this->error("Реинжекция update {$update['update_id']} — HTTP {$response->status()}, курсор не двигаю.");

                break;
            }

            // Курсор двигаем ПОСЛЕ успешной реинжекции и по каждому апдейту.
            Cache::forever(self::OFFSET_KEY, ((int) $update['update_id']) + 1);
        }

        return count($updates);
    }

    /**
     * Освобождает вебхук — но ТОЛЬКО по явному флагу.
     *
     * Пока вебхук зарегистрирован, getUpdates отвечает 409 Conflict. Раньше
     * команда снимала вебхук молча при каждом старте — один случайный запуск
     * тихо уводил бота с рабочей дорожки. Теперь переключение — осознанное
     * действие оператора; вернуть вебхук: php artisan telegram:webhooks --set.
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
                .'Поллинг снял бы его и переключил дорожку. Если это и нужно, запустите с --release-webhook; '
                .'вернуть вебхук обратно: php artisan telegram:webhooks --set'
            );
        }

        $client->deleteWebhook();

        Log::warning('Student poll: webhook removed in favour of long polling', ['was' => $url]);
        $this->warn("Вебхук снят ({$url}) — апдейты теперь забирает long polling.");
    }

    private function maxLifetimeSeconds(): int
    {
        $option = $this->option('max-seconds');

        return $option !== null
            ? max(1, (int) $option)
            : (int) config('services.telegram_student.poll_max_lifetime_seconds', 3600);
    }

    /**
     * SIGTERM от supervisor'а должен дать дожить текущему заходу, а не рвать
     * процесс посреди getUpdates: иначе принятые, но не подтверждённые апдейты
     * придут повторно.
     */
    private function trapTermination(): void
    {
        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function () use ($signal): void {
                $this->shouldStop = true;

                Log::info('Student poll: termination signal, finishing current cycle', ['signal' => $signal]);
            });
        }
    }
}
