<?php

declare(strict_types=1);

namespace App\Services\OpenLessonCards;

use App\Models\BotSubscriber;
use App\Models\OpenLessonRsvp;
use App\Models\Schedule;

/**
 * Входящие апдейты @samskrte_bot для карточек открытых занятий и «воронки»:
 * callback "ol:*" (✅ Я иду / Не смогу / открыть карточку / Участвовать /
 * анкета), нажатия нижнего меню, ответы анкеты текстом и deep-link
 * /start ol_<schedule_id>. Вызывается из ProcessTelegramMagnetUpdate для
 * глобального бота РАНЬШЕ ждуна: ждун забирает себе любой callback_query.
 */
class OpenLessonBotService
{
    public function __construct(
        private readonly OpenLessonOccasions $occasions,
        private readonly OpenLessonCardBuilder $builder,
        private readonly OpenLessonCardSender $sender,
        private readonly OpenLessonTelegram $telegram,
        private readonly OpenLessonFunnel $funnel,
    ) {}

    /**
     * Флаг ON и чат допущен. Пока OPEN_LESSON_CARDS_TEST_CHAT_IDS не пуст,
     * новый бот (приветствие, меню, «Участвовать», кнопки карточек) отвечает
     * только этим чатам — остальные видят прежнее поведение (ждун).
     */
    public function enabledFor(string $chatId): bool
    {
        if (! config('features.open_lesson_cards', false)) {
            return false;
        }

        $testChats = array_map('strval', (array) config('open_lesson_cards.test_chat_ids', []));

        return $testChats === [] || in_array($chatId, $testChats, true);
    }

    /** true — апдейт наш и обработан. */
    public function handleUpdate(array $update): bool
    {
        $callback = $update['callback_query'] ?? null;
        if (is_array($callback)) {
            $data = (string) ($callback['data'] ?? '');
            $callbackChat = (string) ($callback['message']['chat']['id'] ?? $callback['from']['id'] ?? '');
            if (! str_starts_with($data, 'ol:') || ! $this->enabledFor($callbackChat)) {
                return false;
            }
            $this->handleCallback($callback, $data);

            return true;
        }

        $message = $update['message'] ?? null;
        $chatId = is_array($message) ? ($message['chat']['id'] ?? null) : null;
        if ($chatId === null || ($message['chat']['type'] ?? 'private') !== 'private') {
            return false;
        }
        $chatId = (string) $chatId;
        if (! $this->enabledFor($chatId)) {
            return false;
        }
        $text = trim((string) ($message['text'] ?? ''));

        if (preg_match('~^/start\s+ol_(\d+)$~', $text, $m) === 1) {
            $this->remember($message);
            $schedule = $this->occasions->find((int) $m[1]);
            $schedule !== null
                ? $this->sender->sendCard($schedule, $chatId, OpenLessonCardBuilder::STAGE_MANUAL)
                : $this->sendLessonList($chatId, 'Это занятие уже прошло.');
            $this->sender->ensureMenu($chatId);

            return true;
        }

        $action = array_search($text, (array) config('open_lesson_cards.menu', []), true);
        if ($action === false) {
            return $this->funnel->handleText($chatId, $text, (array) ($message['from'] ?? []));
        }

        match ($action) {
            'lessons' => $this->sendLessonList($chatId),
            'program' => $this->funnel->program($chatId),
            'gifts' => $this->funnel->gifts($chatId),
            'site' => $this->funnel->site($chatId),
            'join' => $this->funnel->join($chatId),
            default => null,
        };

        return true;
    }

    /** /start любого вида: чат попадает в аудиторию карточек (повторный старт снимает блокировку). */
    public function remember(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        if ($chatId === null || ($message['chat']['type'] ?? 'private') !== 'private') {
            return;
        }

        $subscriber = BotSubscriber::query()->firstOrNew(['chat_id' => (string) $chatId]);
        $subscriber->source ??= BotSubscriber::SOURCE_START;
        $subscriber->first_name = mb_substr((string) ($message['from']['first_name'] ?? $subscriber->first_name ?? ''), 0, 128) ?: null;
        $subscriber->username = isset($message['from']['username']) ? mb_substr((string) $message['from']['username'], 0, 64) : $subscriber->username;
        $subscriber->blocked_at = null;
        $subscriber->save();
    }

    /**
     * /start без токена при включённом флаге: приветствие-воронка (карточка
     * занятия + подарки + меню) вместо короткого приветствия ждуна.
     * false — флаг выключен, приветствует ждун, как раньше.
     */
    public function welcome(string $chatId): bool
    {
        if (! $this->enabledFor($chatId)) {
            return false;
        }

        $this->funnel->welcome($chatId);

        return true;
    }

    private function handleCallback(array $callback, string $data): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $chatId = (string) ($callback['message']['chat']['id'] ?? $callback['from']['id'] ?? '');
        $messageId = $callback['message']['message_id'] ?? null;

        if ($chatId !== '' && $this->funnel->handleCallback($chatId, $data, $callbackId, (array) ($callback['from'] ?? []))) {
            return;
        }

        if (! preg_match('~^ol:(go|no|card):(\d+)$~', $data, $m) || $chatId === '') {
            $this->answer($callbackId);

            return;
        }

        $schedule = $this->occasions->find((int) $m[2]);
        if ($schedule === null) {
            $this->answer($callbackId, 'Это занятие уже прошло.');

            return;
        }

        switch ($m[1]) {
            case 'go':
                OpenLessonRsvp::query()->updateOrCreate(
                    ['schedule_id' => $schedule->id, 'chat_id' => $chatId],
                    ['going_at' => now(), 'declined_at' => null],
                );
                $this->answer($callbackId, 'Записали! Ссылку пришлём сюда перед началом.');
                $this->editKeyboard($chatId, $messageId, $schedule, 'going');
                $this->sendZoomIfSoon($schedule, $chatId);
                break;

            case 'no':
                OpenLessonRsvp::query()->updateOrCreate(
                    ['schedule_id' => $schedule->id, 'chat_id' => $chatId],
                    ['going_at' => null, 'declined_at' => now()],
                );
                $this->answer($callbackId, 'Жаль! Передумаете — нажмите «Я иду».');
                $this->editKeyboard($chatId, $messageId, $schedule, null);
                break;

            case 'card':
                $this->answer($callbackId);
                $this->sender->sendCard($schedule, $chatId, OpenLessonCardBuilder::STAGE_MANUAL);
                break;
        }
    }

    /** Нажали «Я иду» позже рассылки ссылок — ссылка сразу, не ждём следующего прогона. */
    private function sendZoomIfSoon(Schedule $schedule, string $chatId): void
    {
        $lead = (int) config('open_lesson_cards.zoom_lead_minutes', 30);
        if ($schedule->start->lte(now()->addMinutes($lead))) {
            $this->sender->sendZoom($schedule, $chatId);
        }
    }

    private function sendLessonList(string $chatId, ?string $intro = null): void
    {
        $horizon = (int) config('open_lesson_cards.list_horizon_days', 14);
        $lessons = $this->occasions->between(now(), now()->addDays($horizon)->endOfDay());

        if ($lessons->isEmpty()) {
            $this->telegram->call('sendMessage', [
                'chat_id' => $chatId,
                'text' => trim(($intro ?? '')."\n\nБлижайших открытых занятий пока нет — анонс придёт сюда, как только появится."),
            ]);

            return;
        }

        $rows = $lessons->map(function (Schedule $schedule): array {
            $start = $schedule->start->copy()->timezone(config('app.timezone'))->locale('ru');

            return [[
                'text' => '🎙 '.$start->isoFormat('D MMM, HH:mm').' — '.mb_strimwidth($this->builder->title($schedule), 0, 40, '…'),
                'callback_data' => OpenLessonCardBuilder::CALLBACK_CARD.$schedule->id,
            ]];
        })->all();

        $this->telegram->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => trim(($intro ?? '')."\n\nБлижайшие открытые занятия (время МСК). Нажмите, чтобы открыть карточку и записаться:"),
            'reply_markup' => json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function editKeyboard(string $chatId, mixed $messageId, Schedule $schedule, ?string $answer): void
    {
        if (! is_int($messageId)) {
            return;
        }

        $this->telegram->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => $this->builder->inlineKeyboard($schedule, $answer)], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function answer(string $callbackId, string $text = ''): void
    {
        if ($callbackId === '') {
            return;
        }

        $this->telegram->call('answerCallbackQuery', ['callback_query_id' => $callbackId] + ($text !== '' ? ['text' => $text] : []));
    }
}
