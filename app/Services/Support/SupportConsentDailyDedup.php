<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;

/**
 * H6644 — идемпотентность отправителя согласий ПД в TG.
 *
 * Прод-замер 13-10-2026 (агрегатный зонд, секция P1): исходящие
 * LIKE '%соглас%' уходили одному адресату дублями ×2–4 за день
 * (чаты 21/261/262/597/279, все private). Причина — несколько веток
 * автоответчика (шаблон/FAQ/ack/facts/приглашение) каждая со своим
 * cooldown-инвариантом 6 ч: за сутки окно истекает до четырёх раз, и
 * студент получает тот же текст снова.
 *
 * Инвариант из постановки: per-chat per-day consent-like outgoing ≤ 1.
 * Гейт стоит в единственной горловине постановки исходящих
 * ({@see SupportReplyService::createPendingOutgoing}), поэтому покрывает
 * все ботовые ветки разом. Предикат «consent-like» — тот же стем, что в
 * зонде ('соглас'), чтобы метрика приёмки совпадала с гейтом байт-в-байт.
 *
 * Намеренно НЕ гейтится:
 *  - человек-куратор (responder_type=human): явная отправка куратором —
 *    осознанное действие, молча гасить её нельзя;
 *  - явное подтверждение человека в полосе подсказок
 *    (SupportHintSendButton → bypass): кнопку «Отправить» нажал человек.
 *
 * Счётчик — по строке БД (sent_at >= начала сегодняшнего дня, Europe/Moscow),
 * включая ещё не доставленные pending: повторная постановка при живом
 * pending — тот же класс дубля.
 */
final class SupportConsentDailyDedup
{
    /** Тот же стем, что в зонде P1: LIKE '%соглас%'. */
    public function isConsentLike(string $text): bool
    {
        return mb_stripos($text, 'соглас') !== false;
    }

    /**
     * Последнее consent-like исходящее этого чата сегодня (включая pending).
     * Вызывать внутри блокировки строки чата — см. горловину постановки.
     */
    public function findTodaysOutgoing(TelegramSupportChat $chat): ?TelegramSupportMessage
    {
        return TelegramSupportMessage::query()
            ->where('telegram_support_chat_id', $chat->id)
            ->where('direction', 'outgoing')
            ->whereNotNull('text')
            ->where('text', 'like', '%соглас%')
            ->where('sent_at', '>=', now()->startOfDay())
            ->orderByDesc('sent_at')
            ->first();
    }
}
