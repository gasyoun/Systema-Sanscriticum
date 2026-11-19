<?php

namespace App\Services\TelegramSupport;

use App\Models\SupportDailyRollup;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Services\Support\WebSupportRollupAggregator;
use App\Support\SupportRollupMetrics;
use App\Support\UnifiedMessage;
use Carbon\CarbonImmutable;

/**
 * Дневные rollup'ы TG-support-стороны (импорт userbot'а).
 *
 * С H1837 (S10) арифметика метрик вынесена в {@see SupportRollupMetrics} и общая с
 * веб-стороной ({@see WebSupportRollupAggregator}) — так KPI
 * «unresolved-after-N-hours» и доли ИИ/человеческих ответов значат в двух каналах
 * одно и то же. Здесь остаётся ровно то, что действительно телеграм-специфично:
 * выбор чатов дня и признак «первое обращение контакта».
 *
 * H6645 — ЯВНОЕ правило метрики (не скрытое исключение): входящие от ботов в
 * групповых чатах (group/supergroup) — это автопостинг учебной группы (анонсы,
 * рассылки), а не обращения в поддержку. До фильтра direction-based
 * is_unanswered превращал такой трафик в вечный unanswered-шум и качал
 * SLA-паритет (прод-замер 13-10-2026: 4 из 24 unanswered за окно — автопосты
 * учебного супергруппы; групповых входящих 55, отправители сконцентрированы).
 *
 * Признак бота — платформенный инвариант Telegram: username бота всегда
 * заканчивается суффиксом «bot» (регистронезависимо). Ограничение групповыми
 * чатами сознательное: в приватных DM бот-входящие невозможны (бот не пишет
 * первым), фильтр там не применяется. Человеческий групповой трёп остаётся
 * в метрике намеренно — фильтруются только боты, а не группы. Хранилище
 * сообщений и хелпдеск не меняются: фильтруется только вход расчёта метрик.
 *
 * День группы, где после фильтра не осталось ни одного сообщения, разговором
 * поддержки не был никогда — его rollup-строка удаляется (topic assignments
 * уходят каскадом). Без удаления пересчёт дня не сходился бы с правилом
 * на снимках, снятых до H6645, и окно parity показывало бы устаревший шум.
 */
class SupportDailyRollupAggregator
{
    /**
     * Групповые чат-типы Telegram (см. resolveChatType синка): multi-user чаты,
     * где direction-based метрика встречает не-поддерживающий трафик.
     */
    private const GROUP_CHAT_TYPES = ['group', 'supergroup'];

    public function __construct(
        private readonly SupportTopicClassifier $topicClassifier,
    ) {}

    public function aggregateDate(string|\DateTimeInterface $date): void
    {
        $day = CarbonImmutable::parse($date, config('app.timezone'));
        $start = $day->startOfDay();
        $end = $day->endOfDay();
        $unresolvedAfterHours = (int) config('support.rollup.unresolved_after_hours', 24);

        TelegramSupportChat::query()
            ->whereHas('messages', fn ($query) => $query->whereBetween('sent_at', [$start, $end]))
            ->each(function (TelegramSupportChat $chat) use ($day, $start, $end, $unresolvedAfterHours): void {
                $messages = TelegramSupportMessage::query()
                    ->with(['chat', 'contact', 'responder:id,name'])
                    ->where('telegram_support_chat_id', $chat->id)
                    ->whereBetween('sent_at', [$start, $end])
                    ->orderBy('sent_at')
                    ->get();

                if ($messages->isEmpty()) {
                    return;
                }

                // H6645: бот-автопосты групповых чатов — не обращения в поддержку
                // (правило — в класс-докблоке). Фильтруется вход метрики, не хранилище.
                $supportMessages = $messages
                    ->reject(fn (TelegramSupportMessage $message): bool => $this->isBotGroupAutoPost($chat, $message))
                    ->values();

                if ($supportMessages->isEmpty()) {
                    SupportDailyRollup::query()
                        ->where('telegram_support_chat_id', $chat->id)
                        ->whereDate('conversation_date', $day->toDateString())
                        ->delete();

                    return;
                }

                $hasNewContact = $supportMessages
                    ->where('direction', 'incoming')
                    ->contains(fn (TelegramSupportMessage $message) => $message->contact
                        && $message->contact->first_inbound_at
                        && $message->contact->first_inbound_at->timezone(config('app.timezone'))->toDateString() === $day->toDateString());

                $metrics = SupportRollupMetrics::fromMessages(
                    $supportMessages->map(fn (TelegramSupportMessage $message) => UnifiedMessage::fromTelegramSupportMessage($message)),
                    $unresolvedAfterHours,
                    CarbonImmutable::now(config('app.timezone')),
                );

                $conversation = SupportDailyRollup::updateOrCreate(
                    [
                        'telegram_support_chat_id' => $chat->id,
                        'conversation_date' => $day->startOfDay(),
                    ],
                    $metrics + [
                        'channel' => SupportDailyRollup::CHANNEL_TELEGRAM,
                        'has_new_contact' => $hasNewContact,
                    ],
                );

                $this->topicClassifier->classify($conversation);
            });
    }

    /**
     * Входящее от бота в групповом чате = автопост, не поддержка (H6645).
     *
     * Детерминированный признак только из метаданных, которые уже лежат
     * в хранилище: username отправителя (снимок синка в raw_payload или
     * денормализация на контакте) заканчивается на «bot» — платформенный
     * инвариант Telegram для ботов. Тела сообщений не читаются.
     */
    private function isBotGroupAutoPost(TelegramSupportChat $chat, TelegramSupportMessage $message): bool
    {
        if (! in_array($chat->type, self::GROUP_CHAT_TYPES, true)) {
            return false;
        }

        if ($message->direction !== UnifiedMessage::DIRECTION_INCOMING) {
            return false;
        }

        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $username = $payload['contact_username']
            ?? $payload['sender_username']
            ?? $message->contact?->username;

        return is_string($username) && preg_match('/bot$/i', trim($username)) === 1;
    }
}
