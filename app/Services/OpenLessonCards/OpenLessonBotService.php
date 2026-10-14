<?php

declare(strict_types=1);

namespace App\Services\OpenLessonCards;

use App\Models\BotSubscriber;
use App\Models\OpenLessonRsvp;
use App\Models\Schedule;
use App\Services\Telegram\WaitlistBotService;

/**
 * Входящие апдейты @samskrte_bot для карточек открытых занятий:
 * callback "ol:*" (✅ Я иду / Не смогу / открыть карточку), нажатия нижнего
 * меню и deep-link /start ol_<schedule_id>. Вызывается из
 * ProcessTelegramMagnetUpdate для глобального бота РАНЬШЕ ждуна: ждун
 * забирает себе любой callback_query.
 */
class OpenLessonBotService
{
    public function __construct(
        private readonly OpenLessonOccasions $occasions,
        private readonly OpenLessonCardBuilder $builder,
        private readonly OpenLessonCardSender $sender,
        private readonly OpenLessonTelegram $telegram,
    ) {}

    /** true — апдейт наш и обработан. */
    public function handleUpdate(array $update): bool
    {
        if (! config('features.open_lesson_cards', false)) {
            return false;
        }

        $callback = $update['callback_query'] ?? null;
        if (is_array($callback)) {
            $data = (string) ($callback['data'] ?? '');
            if (! str_starts_with($data, 'ol:')) {
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
            return false;
        }

        match ($action) {
            'lessons' => $this->sendLessonList($chatId),
            'zhdun' => $this->sendZhdun($chatId),
            'site' => $this->sendLink($chatId, 'Сайт школы — курсы, расписание, кабинет:', '🌐 samskrte.ru', (string) config('open_lesson_cards.site_url')),
            'enroll' => $this->sendLink($chatId, 'Расписание курсов и запись в группы:', '✍️ Выбрать курс', (string) config('open_lesson_cards.enroll_url')),
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
        $subscriber->blocked_at = null;
        $subscriber->save();
    }

    /** Меню после приветствия /start (флаг ON). */
    public function afterWelcome(string $chatId): void
    {
        if (config('features.open_lesson_cards', false)) {
            $this->sender->ensureMenu($chatId);
        }
    }

    private function handleCallback(array $callback, string $data): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $chatId = (string) ($callback['message']['chat']['id'] ?? $callback['from']['id'] ?? '');
        $messageId = $callback['message']['message_id'] ?? null;

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

    private function sendZhdun(string $chatId): void
    {
        if (config('features.waitlist_voting', false)) {
            app(WaitlistBotService::class)->sendList($chatId);

            return;
        }

        $this->sendLink($chatId, 'Ждун — голосование за будущие группы:', '⏳ Открыть ждуна', rtrim((string) config('open_lesson_cards.site_url'), '/').'/online/zhdun');
    }

    private function sendLink(string $chatId, string $text, string $button, string $url): void
    {
        $this->telegram->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => $button, 'url' => $url]]]], JSON_UNESCAPED_UNICODE),
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
