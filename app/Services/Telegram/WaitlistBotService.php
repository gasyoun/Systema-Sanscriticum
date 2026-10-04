<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\CourseWaitlistItem;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Models\WaitlistVote;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ждун в @samskrte_bot (MG 04-10-2026: функции ждуна — дублировать в бот,
 * кому как удобнее). Зеркало сайт-механики /online/zhdun: те же
 * CourseWaitlistItem/WaitlistVote, тот же registered-only рулинг
 * (MG 31-08-2026) — голос привязан к cabinet-юзеру через users.telegram_id,
 * непривязанный чат получает инструкцию привязки и голоса не оставляет.
 *
 * Обрабатывает /zhdun и callback_query с префиксом "wl:"; вызывается из
 * ProcessTelegramMagnetUpdate только для глобального бота. Все ответы
 * Telegram глотаются с логом — ошибка отправки не должна ронять джобу
 * (Telegram сделает свой ретрай вебхука).
 */
final class WaitlistBotService
{
    private const PAGE_SIZE = 8;

    private const CALLBACK_PREFIX = 'wl:';

    public function __construct() {}

    /** true — апдейт наш и обработан, джобе дальше идти нечего. */
    public function handleUpdate(array $update): bool
    {
        if (! config('features.waitlist_voting', false)) {
            return false;
        }

        $callback = $update['callback_query'] ?? null;
        if (is_array($callback)) {
            $this->handleCallback($callback);

            return true;
        }

        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        $chatId = $message['chat']['id'] ?? null;
        if (! $chatId) {
            return false;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $command = strtolower(explode('@', explode(' ', $text)[0])[0]);
        if ($command === '/zhdun') {
            $this->sendList((string) $chatId);

            return true;
        }

        return false;
    }

    /** Приветствие на /start без токена: кто мы и где ждун. */
    public function sendWelcome(string $chatId): void
    {
        $this->tg('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Привет! Это бот Общества ревнителей санскрита.\n\n"
                ."📋 /zhdun — голосование за будущие группы (ждун)\n"
                .'Оплата курсов и материалы — на https://samskrte.ru',
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $this->keyboard([[
                ['text' => '📋 Ждун — голосование за курсы', 'callback_data' => self::CALLBACK_PREFIX.'page:0'],
            ]]),
        ]);
    }

    public function sendList(string $chatId, int $page = 0, ?int $editMessageId = null): void
    {
        $items = $this->listedItems();
        $pages = max(1, (int) ceil($items->count() / self::PAGE_SIZE));
        $page = max(0, min($page, $pages - 1));
        $slice = $items->slice($page * self::PAGE_SIZE, self::PAGE_SIZE);

        $text = "📋 <b>Ждун — будущие группы ОРС</b>\n"
            ."Наберётся минимум голосов — откроется оплата; нужное число оплат к сроку — группа стартует.\n"
            .'Страница '.($page + 1).'/'.$pages.' · всего курсов: '.$items->count();

        $rows = [];
        foreach ($slice as $item) {
            $rows[] = [[
                'text' => $this->itemLabel($item),
                'callback_data' => self::CALLBACK_PREFIX.'pick:'.$item->getKey(),
            ]];
        }

        $footer = [['text' => '🗳 Мои голоса', 'callback_data' => self::CALLBACK_PREFIX.'mine']];
        if ($pages > 1) {
            $nav = [];
            if ($page > 0) {
                $nav[] = ['text' => '◀️', 'callback_data' => self::CALLBACK_PREFIX.'page:'.($page - 1)];
            }
            if ($page < $pages - 1) {
                $nav[] = ['text' => '▶️', 'callback_data' => self::CALLBACK_PREFIX.'page:'.($page + 1)];
            }
            $rows[] = $nav;
            $footer[] = ['text' => '🔄 Обновить', 'callback_data' => self::CALLBACK_PREFIX.'page:'.$page];
        }
        $rows[] = $footer;

        $payload = [
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $this->keyboard($rows),
        ];

        if ($editMessageId !== null) {
            $payload['message_id'] = $editMessageId;
            $this->tg('editMessageText', ['chat_id' => $chatId] + $payload);

            return;
        }
        $this->tg('sendMessage', ['chat_id' => $chatId] + $payload);
    }

    private function handleCallback(array $callback): void
    {
        $cbId = (string) ($callback['id'] ?? '');
        $chatId = (string) ($callback['message']['chat']['id'] ?? '');
        $messageId = $callback['message']['message_id'] ?? null;
        $data = (string) ($callback['data'] ?? '');
        $answer = '';

        try {
            if (! str_starts_with($data, self::CALLBACK_PREFIX) || $chatId === '') {
                $this->answer($cbId);

                return;
            }

            $parts = explode(':', substr($data, strlen(self::CALLBACK_PREFIX)));

            match ($parts[0]) {
                'page' => $this->sendList($chatId, (int) ($parts[1] ?? 0), is_numeric($messageId) ? (int) $messageId : null),
                'pick' => $this->askSlot($chatId, is_numeric($messageId) ? (int) $messageId : null, (int) ($parts[1] ?? 0)),
                'do' => $this->castVote($chatId, is_numeric($messageId) ? (int) $messageId : null, (int) ($parts[1] ?? 0), (string) ($parts[2] ?? '')),
                'mine' => $this->sendMine($chatId, is_numeric($messageId) ? (int) $messageId : null),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('WaitlistBotService: callback failed', [
                'data' => $data,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $answer = 'Ошибка, попробуйте ещё раз';
        } finally {
            $this->answer($cbId, $answer);
        }
    }

    private function askSlot(string $chatId, ?int $messageId, int $itemId): void
    {
        $item = $this->listedItems()->firstWhere('id', $itemId);
        if ($item === null) {
            $this->replace($chatId, $messageId, 'Этот курс уже не в списке ожидания.', [[['text' => '◀️ К списку', 'callback_data' => self::CALLBACK_PREFIX.'page:0']]]);

            return;
        }

        $rows = [];
        foreach (WaitlistVote::SLOT_PREFERENCES as $key => $label) {
            $rows[] = [['text' => $label, 'callback_data' => self::CALLBACK_PREFIX.'do:'.$itemId.':'.$key]];
        }
        $rows[] = [['text' => 'В любое время', 'callback_data' => self::CALLBACK_PREFIX.'do:'.$itemId.':any']];
        $rows[] = [['text' => '◀️ К списку курсов', 'callback_data' => self::CALLBACK_PREFIX.'page:0']];

        $this->replace($chatId, $messageId,
            '<b>'.e($item->course_title).'</b>'
            .($item->teacher_name ? "\nПреподаватель: ".e($item->teacher_name) : '')
            ."\n\nКогда вам удобнее заниматься?",
            $rows);
    }

    private function castVote(string $chatId, ?int $messageId, int $itemId, string $slot): void
    {
        $user = User::query()->where('telegram_id', (int) $chatId)->first();

        if (! $user instanceof User) {
            $this->replace($chatId, $messageId,
                "Голосовать могут ученики с кабинетом (рулинг MG 31-08-2026, как и на сайте).\n\n"
                .'Привяжите Telegram: войдите на https://samskrte.ru/telegram/connect '
                .'— и нажмите кнопку курса снова.',
                [[['text' => '◀️ К списку курсов', 'callback_data' => self::CALLBACK_PREFIX.'page:0']]]);

            return;
        }

        $item = $this->listedItems()->firstWhere('id', $itemId);
        if ($item === null) {
            $this->replace($chatId, $messageId, 'Этот курс уже не в списке ожидания.', [[['text' => '◀️ К списку', 'callback_data' => self::CALLBACK_PREFIX.'page:0']]]);

            return;
        }

        $slotKey = $slot === 'any' ? null : (in_array($slot, array_keys(WaitlistVote::SLOT_PREFERENCES), true) ? $slot : null);
        $item->castVoteBy($user, $slotKey);

        $slotLine = $slotKey === null ? '' : "\nВремя: ".WaitlistVote::SLOT_PREFERENCES[$slotKey];
        $this->replace($chatId, $messageId,
            '✅ Голос учтён: <b>'.e($item->course_title).'</b>'
            .$slotLine
            ."\nПрогресс: {$item->votesCount()} / {$item->min_payers} голосов — "
            .'после минимума откроется оплата.'
            ."\n\nПереголосовать за другой курс — «К списку».",
            [
                [['text' => '◀️ К списку курсов', 'callback_data' => self::CALLBACK_PREFIX.'page:0'], ['text' => '🗳 Мои голоса', 'callback_data' => self::CALLBACK_PREFIX.'mine']],
            ]);
    }

    private function sendMine(string $chatId, ?int $messageId): void
    {
        $user = User::query()->where('telegram_id', (int) $chatId)->first();
        if (! $user instanceof User) {
            $this->replace($chatId, $messageId,
                'У вас пока нет привязанного кабинета — войдите на https://samskrte.ru/telegram/connect.',
                [[['text' => '◀️ К списку курсов', 'callback_data' => self::CALLBACK_PREFIX.'page:0']]]);

            return;
        }

        $votes = WaitlistVote::query()
            ->where('user_id', $user->getKey())
            ->with('item:id,course_title,min_payers')
            ->get();

        if ($votes->isEmpty()) {
            $this->replace($chatId, $messageId, 'Вы пока не голосовали. Выберите курс в списке — и он ближе к старту.', [[['text' => '◀️ К списку курсов', 'callback_data' => self::CALLBACK_PREFIX.'page:0']]]);

            return;
        }

        $lines = $votes->map(function (WaitlistVote $vote): string {
            $item = $vote->item;
            $title = $item?->course_title ?? 'курс';
            $slot = $vote->slot_preference !== null && isset(WaitlistVote::SLOT_PREFERENCES[$vote->slot_preference])
                ? ' ('.WaitlistVote::SLOT_PREFERENCES[$vote->slot_preference].')' : '';

            return '• '.e($title).$slot
                .($item ? " — {$item->votesCount()} / {$item->min_payers}" : '');
        })->implode("\n");

        $this->replace($chatId, $messageId, "🗳 <b>Ваши голоса</b>\n\n".$lines, [[['text' => '◀️ К списку курсов', 'callback_data' => self::CALLBACK_PREFIX.'page:0']]]);
    }

    /**
     * Та же выборка, что на сайте (ShopController::waitlist): без закрытых
     * и запланированных, порядок витрины.
     */
    private function listedItems()
    {
        return CourseWaitlistItem::query()
            ->where('is_listed', true)
            ->whereNotIn('status', [CourseWaitlistItem::STATUS_CLOSED, CourseWaitlistItem::STATUS_SCHEDULED])
            ->withCount('votes')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function itemLabel(CourseWaitlistItem $item): string
    {
        $label = $item->course_title ?: 'Курс';
        if ($item->teacher_name) {
            $label .= ' · '.$item->teacher_name;
        }

        return $label.' — '.$item->votes_count.' / '.$item->min_payers;
    }

    private function replace(string $chatId, ?int $messageId, string $text, array $rows): void
    {
        $payload = [
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $this->keyboard($rows),
        ];
        if ($messageId !== null) {
            $this->tg('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId] + $payload);

            return;
        }
        $this->tg('sendMessage', ['chat_id' => $chatId] + $payload);
    }

    private function keyboard(array $rows): string
    {
        return (string) json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }

    private function answer(string $cbId, string $text = ''): void
    {
        if ($cbId === '') {
            return;
        }
        $this->tg('answerCallbackQuery', ['callback_query_id' => $cbId] + ($text !== '' ? ['text' => $text] : []));
    }

    /**
     * Тот же токен, что у TelegramDeliveryChannel (глобальный бот из
     * MarketingSetting). Ошибки доставки логируются и глотаются.
     */
    private function tg(string $method, array $payload): void
    {
        $token = (string) (MarketingSetting::cached()?->tg_bot_token ?? '');
        if ($token === '') {
            return;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
            if (! $response->json('ok')) {
                Log::warning('WaitlistBotService: telegram api not ok', [
                    'method' => $method,
                    'description' => $response->json('description'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WaitlistBotService: telegram api failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
