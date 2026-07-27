<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Models\MarketingSetting;
use App\Services\Messaging\TelegramDeliveryChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Track C (H164): приём апдейтов @zapisi_ORSbot через long polling вместо вебхука.
 *
 * Зачем не вебхук. Telegram не может достучаться до этого прода: входящие
 * соединения из его подсетей не проходят (27.07.2026 — «Connection timed out»
 * при живом сайте и работающих вебхуках Точки). До 22.07.2026 апдейты приходили
 * через промежуточный туннель (в логах nginx — источник 10.9.0.1), после его
 * пропажи вебхук замолчал, и пять дней это было незаметно. Исходящий канал при
 * этом работает: `tg-tunnel.service` (sshuttle) заворачивает подсети Telegram
 * через зарубежный хост. Long polling ходит ровно по этому каналу и не зависит
 * ни от какого проброса портов.
 *
 * Апдейты отдаются в ТОТ ЖЕ {@see ProcessTelegramZapisiUpdate}, что и вебхук, —
 * формат `update` идентичен, дорожка хранения одна.
 *
 * Демон: висит до `poll_max_lifetime_seconds`, затем выходит с кодом 0, и
 * supervisor поднимает свежий процесс — так после деплоя поллер не остаётся на
 * старом коде (та же логика, что у рестарта Horizon в deploy.sh).
 */
class PollTelegramZapisiUpdates extends Command
{
    protected $signature = 'zapisi:poll
        {--once : Один заход getUpdates и выход (для проверки/тестов)}
        {--max-seconds= : Сколько секунд жить перед плановым выходом (по умолчанию из конфига)}
        {--release-webhook : Снять зарегистрированный вебхук и забирать апдейты поллингом}';

    protected $description = 'АВАРИЙНЫЙ приём апдейтов @zapisi_ORSbot поллингом — когда входной узел вебхуков недоступен. Штатно апдейты приходят вебхуком.';

    /** Ключ курсора: update_id, с которого продолжаем. Подтверждает приём Telegram'у. */
    private const OFFSET_KEY = 'zapisi:poll:offset';

    private bool $shouldStop = false;

    public function handle(TelegramDeliveryChannel $telegram): int
    {
        if (! config('features.telegram_zapisi_bot')) {
            $this->info('Track C (@zapisi_ORSbot) выключен через TELEGRAM_ZAPISI_BOT_ENABLED — пропуск.');

            return self::SUCCESS;
        }

        // Свой рубильник, отдельный от трека: поллинг — аварийный режим, штатно
        // апдейты приходят вебхуком. Дефолт false, чтобы случайный запуск
        // (в т.ч. `supervisorctl restart` остановленной программы) не уводил
        // бота с вебхука молча.
        if (! config('services.telegram_zapisi.poll_enabled', false)) {
            $this->info('Аварийный поллинг выключен (TELEGRAM_ZAPISI_POLL_ENABLED) — штатно апдейты забирает вебхук.');

            return self::SUCCESS;
        }

        $settings = MarketingSetting::cached();
        $token = (string) ($settings?->zapisi_bot_token ?? '');
        if ($token === '') {
            $this->error('zapisi_bot_token не задан в MarketingSetting — поллить нечем.');

            return self::FAILURE;
        }

        $client = $telegram->usingCredentials($token, (string) ($settings?->zapisi_bot_username ?? ''));

        // Пока вебхук зарегистрирован, getUpdates отвечает 409 Conflict: Telegram
        // отдаёт апдейты ровно одним способом. Снимаем его явно и однократно.
        try {
            $this->releaseWebhook($client);
        } catch (Throwable $e) {
            $this->error('Не удалось снять вебхук: '.$e->getMessage());

            return self::FAILURE;
        }

        $pollTimeout = (int) config('services.telegram_zapisi.poll_timeout_seconds', 50);
        $deadline = $this->option('once')
            ? null
            : now()->addSeconds($this->maxLifetimeSeconds())->getTimestamp();

        $this->trapTermination();

        $handled = 0;
        do {
            try {
                $handled += $this->pollOnce($client, $pollTimeout);
            } catch (Throwable $e) {
                Log::error('Zapisi poll: getUpdates failed', ['error' => $e->getMessage()]);
                $this->error('getUpdates: '.$e->getMessage());

                // Пауза, чтобы при недоступности Telegram не молотить впустую.
                sleep((int) config('services.telegram_zapisi.poll_retry_seconds', 10));
            }
        } while (! $this->option('once') && ! $this->shouldStop && time() < $deadline);

        $this->info("Zapisi poll: обработано апдейтов — {$handled}.");

        return self::SUCCESS;
    }

    /**
     * Один заход long polling. Возвращает число принятых апдейтов.
     */
    private function pollOnce(TelegramDeliveryChannel $client, int $pollTimeout): int
    {
        $offset = (int) Cache::get(self::OFFSET_KEY, 0);

        // allowed_updates те же, что регистрировал вебхук (ZapisiSetWebhook):
        // группа шлёт message, канал — channel_post.
        $updates = $client->getUpdates($offset, $pollTimeout, ['message', 'channel_post']);

        foreach ($updates as $update) {
            if (! is_array($update) || ! isset($update['update_id'])) {
                continue;
            }

            ProcessTelegramZapisiUpdate::dispatch($update);

            // Курсор двигаем ПОСЛЕ постановки в очередь и по каждому апдейту:
            // падение процесса тогда стоит максимум одного повторно принятого
            // апдейта, а не всей пачки.
            Cache::forever(self::OFFSET_KEY, ((int) $update['update_id']) + 1);
        }

        return count($updates);
    }

    /**
     * Освобождает вебхук — но ТОЛЬКО по явному флагу.
     *
     * Пока вебхук зарегистрирован, getUpdates отвечает 409 Conflict: Telegram
     * отдаёт апдейты ровно одним способом. Раньше команда снимала вебхук молча
     * при каждом старте — то есть один случайный запуск процесса тихо уводил
     * бота с рабочей дорожки, и заметить это было нечем. Теперь переключение —
     * осознанное действие оператора.
     */
    private function releaseWebhook(TelegramDeliveryChannel $client): void
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

        Log::warning('Zapisi poll: webhook removed in favour of long polling', ['was' => $url]);
        $this->warn("Вебхук снят ({$url}) — апдейты теперь забирает long polling.");
    }

    private function maxLifetimeSeconds(): int
    {
        $option = $this->option('max-seconds');

        return $option !== null
            ? max(1, (int) $option)
            : (int) config('services.telegram_zapisi.poll_max_lifetime_seconds', 3600);
    }

    /**
     * SIGTERM от supervisor'а (`supervisorctl stop/restart`) должен дать
     * дожить текущему заходу, а не рвать процесс посреди getUpdates: иначе
     * принятые, но не подтверждённые апдейты придут повторно.
     */
    private function trapTermination(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->shouldStop = true;
        });
    }
}
