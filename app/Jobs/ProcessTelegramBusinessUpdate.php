<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TelegramBusinessConnection;
use App\Services\TelegramBusiness\BusinessSupportReplyDrainer;
use App\Services\TelegramBusiness\TelegramBusinessNormalizer;
use App\Services\TelegramBusiness\TelegramBusinessStoryPublisher;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use App\Support\TelegramSendGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H5065 — один апдейт Telegram Business.
 *
 * Три типа апдейтов, и у каждого своя судьба:
 *  - `business_connection` — подключение/отключение бота к аккаунту: пишем
 *    строку подключения (право can_reply приходит и отзывается здесь же);
 *  - `business_message` / `edited_business_message` — нормализуем и отдаём в
 *    общий support-инбокс тем же путём, что MadelineProto-синк, включая прогон
 *    автоответа;
 *  - `deleted_business_messages` — только лог: удаление в Telegram не должно
 *    удалять историю поддержки (она уже в базе и участвует в аналитике).
 *
 * Дедуп по update_id — тем же клеймом TelegramSendGuard::claimUpdate, что у
 * zapisi-апдейтов: ределивери вебхука не должен приводить к второму ответу
 * студенту.
 */
class ProcessTelegramBusinessUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @param array<string, mixed> $update */
    public function __construct(public readonly array $update)
    {
        $this->onQueue('webhooks');
    }

    public function handle(
        TelegramBusinessNormalizer $normalizer,
        TelegramSupportSyncService $sync,
        BusinessSupportReplyDrainer $drainer,
        TelegramBusinessStoryPublisher $storyPublisher,
    ): void {
        if (! (bool) config('features.telegram_business_bot', false)) {
            return;
        }

        $updateId = isset($this->update['update_id']) ? (int) $this->update['update_id'] : null;
        if ($updateId !== null && ! TelegramSendGuard::claimUpdate('business', $updateId)) {
            Log::info('ProcessTelegramBusinessUpdate: update already processed, duplicate suppressed', [
                'update_id' => $updateId,
            ]);

            return;
        }

        if (isset($this->update['business_connection']) && is_array($this->update['business_connection'])) {
            $this->recordConnection($this->update['business_connection']);

            return;
        }

        if (isset($this->update['channel_post']) && is_array($this->update['channel_post'])) {
            $storyPublisher->publishFromChannelPost($this->update['channel_post']);

            return;
        }

        if (isset($this->update['deleted_business_messages'])) {
            // Осознанное «ничего»: поддержка хранит историю, удаление в
            // Telegram её не переписывает.
            Log::info('ProcessTelegramBusinessUpdate: deleted_business_messages ignored (history is kept)');

            return;
        }

        $message = $this->update['business_message'] ?? $this->update['edited_business_message'] ?? null;
        if (! is_array($message)) {
            return;
        }

        $connectionId = (string) ($message['business_connection_id'] ?? '');
        $connection = $connectionId === '' ? null : TelegramBusinessConnection::query()
            ->where('business_connection_id', $connectionId)
            ->first();

        // Нет строки подключения — не можем отличить студента от владельца.
        // Отвечать вслепую нельзя: ошибка классификации означает автоответ на
        // собственное сообщение школы. Поэтому тихо (но громко в логе) выходим.
        if ($connection === null) {
            Log::warning('ProcessTelegramBusinessUpdate: unknown business_connection_id, message skipped', [
                'business_connection_id' => $connectionId,
            ]);

            return;
        }

        $payload = $normalizer->normalize($message, $connection);
        if ($payload === null) {
            return;
        }

        $accountName = (string) config('services.telegram_business.account_name', 'telegram-business');
        $sync->syncNormalizedMessages([$payload], $accountName);

        // Досылаем сразу: Bot API не боится воркера (в отличие от MadelineProto,
        // где очередь и event loop несовместимы), поэтому ответ уходит через
        // секунды. Расписание support:business-drain остаётся страховкой на
        // потерянный джоб, а не основным путём.
        try {
            $drainer->drain();
        } catch (Throwable $e) {
            Log::warning('ProcessTelegramBusinessUpdate: immediate drain failed, scheduled drain will retry', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function recordConnection(array $connection): void
    {
        $id = (string) ($connection['id'] ?? '');
        if ($id === '') {
            return;
        }

        $ownerId = isset($connection['user']['id']) ? (int) $connection['user']['id'] : 0;
        if ($ownerId === 0) {
            // Без владельца подключение бесполезно: именно по нему
            // классифицируются сообщения.
            Log::warning('ProcessTelegramBusinessUpdate: business_connection without owner, ignored', ['id' => $id]);

            return;
        }

        $enabled = (bool) ($connection['is_enabled'] ?? false);
        $rights = is_array($connection['rights'] ?? null) ? $connection['rights'] : null;

        // H5065: право ответа читается из `rights` (BusinessBotRights.can_reply) —
        // так велит документация Business-ботов: «check your bot's permissions in
        // the rights field … including can_reply». Верхнеуровневый `can_reply` в
        // объекте BusinessConnection остаётся как фолбэк для старых payload'ов;
        // если нет ни того, ни другого — false (fail-closed), и
        // `telegram-business:status` назовёт это блокером, а не промолчит.
        $canReply = (bool) ($rights['can_reply'] ?? $connection['can_reply'] ?? false);

        $existing = TelegramBusinessConnection::query()->where('business_connection_id', $id)->first();

        TelegramBusinessConnection::updateOrCreate(
            ['business_connection_id' => $id],
            [
                'owner_telegram_user_id' => $ownerId,
                'owner_chat_id' => isset($connection['user_chat_id']) ? (int) $connection['user_chat_id'] : null,
                'can_reply' => $canReply,
                'is_enabled' => $enabled,
                'rights' => $rights,
                'connected_at' => $enabled ? ($existing?->connected_at ?? now()) : $existing?->connected_at,
                'disabled_at' => $enabled ? null : now(),
            ],
        );

        Log::info('Telegram Business connection recorded', [
            'business_connection_id' => $id,
            'owner_telegram_user_id' => $ownerId,
            'is_enabled' => $enabled,
            'can_reply' => $canReply,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ProcessTelegramBusinessUpdate failed permanently', [
            'update_id' => $this->update['update_id'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
