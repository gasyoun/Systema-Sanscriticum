<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H3617 — сенсор эха канала (cross-sender дедуп).
 *
 * Бот-админ канала получает КАЖДЫЙ пост канала как `channel_post`-апдейт —
 * включая посты, отправленные НЕ нами: запланированные в самом Telegram
 * сообщения, ручные посты админов, другие боты. Записываем отпечаток
 * (chat_id, текст) каждого такого поста, чтобы издатель канала
 * (marathon:publish-channel-posts) мог отказаться отправить текст, который
 * уже есть в канале.
 *
 * Зачем: TelegramSendGuard дедуплирует отправки только ВНУТРИ Laravel.
 * Инцидент 28-08-2026: запланированное в Telegram сообщение (10:00:05 MSK)
 * и H1936-крон (10:00:09) опубликовали одинаковый текст дважды — ни один
 * пер-системный guard второй отправитель не видит. Эхо-сенсор делает
 * канал самим себе дедуп-сигналом.
 *
 * Fail-open: если кэш недоступен, сенсор молча деградирует (шлём как раньше)
 * с громким warning — дедуп оптимизация против дублей, а не гейт доставки.
 *
 * Хеш считается по финальному ТЕКСТУ поста (то, что Telegram возвращает в
 * `channel_post.text`: разметка уже отрендерена, {link} подставлен до
 * отправки) — издатель сравнивает ту же сырую форму, без HTML-экранирования.
 */
final class TelegramChannelEcho
{
    /** Сколько держим отпечаток (диагностика инцидентов задним числом). */
    private const RECORD_TTL_SECONDS = 604800; // 7 дней

    /** Окно отказа от отправки: идентичный текст уже в канале. */
    public const REFUSAL_WINDOW_SECONDS = 86400; // 24 часа

    /**
     * Записать пост канала из Telegram-апдейта. Молча пропускает апдейты
     * без chat/text (медиа без подписи и т.п.) — сенсор про текстовые посты.
     */
    public static function recordFromUpdate(array $channelPost): void
    {
        $chatId = $channelPost['chat']['id'] ?? null;
        $text = $channelPost['text'] ?? $channelPost['caption'] ?? null;

        if ($chatId === null || ! is_string($text) || $text === '') {
            return;
        }

        self::record((string) $chatId, $text, $channelPost['message_id'] ?? null);
    }

    public static function record(string $chatId, string $text, int|string|null $messageId = null): void
    {
        try {
            Cache::store()->put(self::key($chatId, $text), [
                'message_id' => $messageId,
                'recorded_at' => now()->getTimestamp(),
            ], self::RECORD_TTL_SECONDS);
        } catch (Throwable $exception) {
            Log::warning('TelegramChannelEcho: record failed (cache unavailable)', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * true = идентичный текст уже приходил эхом из канала в последние
     * $seconds секунд → издателю надо отказаться от повторной отправки.
     */
    public static function seenRecently(string $chatId, string $text, int $seconds = self::REFUSAL_WINDOW_SECONDS): bool
    {
        try {
            $entry = Cache::store()->get(self::key($chatId, $text));
        } catch (Throwable $exception) {
            Log::warning('TelegramChannelEcho: lookup failed, fail-open (cache unavailable)', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if (! is_array($entry)) {
            return false;
        }

        $age = now()->getTimestamp() - (int) ($entry['recorded_at'] ?? 0);

        return $age >= 0 && $age <= max(1, $seconds);
    }

    public static function key(string $chatId, string $text): string
    {
        return 'tg:channelecho:'.hash('sha256', $chatId."\x00".$text);
    }
}
