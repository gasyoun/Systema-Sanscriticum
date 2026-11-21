<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\MarketingSetting;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Facades\Log;

/**
 * MG 08-09: преподаватели не знают грамматику команд отмены. Если владелец
 * команды (whitelist H4199 / панельный пользователь H4253) пишет «отмен…»,
 * но ни одна команда не распозналась — бот один раз в сутки на чат
 * подсказывает формат. Студентам и незнакомцам подсказка не уходит.
 */
final class CancelUsageHint
{
    public const TEXT = 'Чтобы отменить занятие: ответьте «Отмена занятия» на пост-напоминание бота — '
        .'это сдвинет цепочку на неделю (по умолчанию). Либо напишите «Отмена 08.09» — '
        .'уберёт только эту дату, без сдвига. Можно добавить причину: «Отмена 08.09 — гос. каникулы». '
        .'Серия занятий закончилась совсем? Напишите «стоп» — бот предложит остановиться в этой группе.';

    private const CLAIM_TTL_SECONDS = 86400;

    /**
     * Входной фильтр вебхука: подсказывать только распознанным sender'ам
     * (whitelist H4199 / панельный пользователь H4253) и только когда
     * сообщение НЕ адресовано ни одной команде отмены.
     *
     * @param  array<string, mixed>  $message
     */
    public static function maybeSendFor(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        if (! is_numeric($chatId)) {
            return;
        }

        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($message['text'] ?? ''))));
        if ($text === '' || ! str_starts_with($text, 'отмен')) {
            return;
        }

        // Текст совпал с командой И это reply — сервисы сами его исполнят.
        $hasReply = is_numeric($message['reply_to_message']['message_id'] ?? null);
        if ($hasReply && (CancelClassCommandService::matches($message) || DateAwareCancelService::matches($message))) {
            return;
        }

        if (! self::senderRecognized($message)) {
            return;
        }

        // H4519: если на этот текст бот предложит кнопку отмены — подсказка
        // формата избыточна (учителя не обязаны знать грамматику команд).
        if (AnnounceCancelService::enabled() && app(AnnounceCancelService::class)->planOffer($message) !== null) {
            return;
        }

        // H8526: если на этот текст бот предложит кнопку останова группы
        // («отмена всех занятий» = «стоп») — подсказка формата одиночной
        // отмены сбивает с толку.
        if (app(StopGroupIntentService::class)->planOffer($message)) {
            return;
        }

        self::maybeSend((string) $chatId, md5($text));
    }

    /** Whitelist H4199 (telegram user_id через запятую) или панельный пользователь (H4253 ACL). */
    private static function senderRecognized(array $message): bool
    {
        $fromId = $message['from']['id'] ?? null;
        if (! is_numeric($fromId)) {
            return false;
        }
        $fromId = (int) $fromId;

        $raw = trim((string) (MarketingSetting::cached()?->zapisi_cancel_admin_ids ?? ''));
        $whitelist = collect(preg_split('/[,\s;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        if ($whitelist->contains($fromId)) {
            return true;
        }

        return app(TelegramCommandAcl::class)->resolve($fromId) !== null;
    }

    /**
     * Клейм ПО ТЕКСТУ (H8526): один и тот же неузнанный «отмен…»-текст — не
     * чаще раза в сутки, но ОТЛИЧАЮЩИЙСЯ текст подсказывает снова. Инцидент
     * гр.57 20-11-2026: «отмени занятия» получило подсказку, а «отмена всех
     * занятий» минутой позже съелось дневным клеймом и выглядело игнором.
     * null-хеш (внешние вызовы) — старое поведение, клейм на чат.
     */
    public static function maybeSend(string $chatId, ?string $textHash = null): void
    {
        $key = 'tg:cancel-hint:'.$chatId.($textHash !== null ? ':'.$textHash : '');
        if (! TelegramSendGuard::claimKey($key, self::CLAIM_TTL_SECONDS)) {
            Log::info('CancelUsageHint: already shown for this chat within TTL, suppressed', [
                'chat_id' => $chatId,
                'text_hash' => $textHash,
            ]);

            return;
        }

        SendZapisiBotMessageJob::dispatch($chatId, self::TEXT);

        Log::info('CancelUsageHint: usage hint sent', ['chat_id' => $chatId, 'text_hash' => $textHash]);
    }
}
