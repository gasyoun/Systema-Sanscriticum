<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Bot\CuratorAi;
use App\Services\Bot\StudentSelfService;
use App\Services\Support\SupportConversationManager;
use Illuminate\Support\Facades\Cache;

/**
 * Обработка входящего сообщения студента из ВЕБ-чата кабинета. Канал-независимый
 * аналог TelegramWebhookController::processStudentQuestion: сохраняет сообщения в
 * ChatMessage и переиспользует тот же «мозг» (CuratorAi) и self-service. Доставка
 * не нужна — кабинет читает ChatMessage напрямую; куратор отвечает из Helpdesk/
 * Dialogs (там же видит непрочитанное role=user).
 */
class StudentChatService
{
    /** Слова-триггеры передачи живому куратору (как в боте). */
    private const HUMAN_TRIGGERS = ['куратор', 'человек', 'помощь', 'админ', 'менеджер', 'оператор'];

    public function __construct(
        private StudentSelfService $selfService,
        private CuratorAi $ai,
        private SupportConversationManager $conversations,
    ) {}

    /** Ключ «режима человека» для веб-канала (по пользователю, не по chat_id). */
    private function humanModeKey(User $user): string
    {
        return "chat_human_web_{$user->id}";
    }

    /**
     * Сохранить входящее сообщение студента (мгновенно, чтобы оно сразу появилось
     * в кабинете). is_read=false → подсветится куратору в Helpdesk/Dialogs.
     */
    public function recordIncoming(User $user, string $text): ?ChatMessage
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $message = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => $text,
            'is_read' => false,
        ]);

        // Входящее открывает/переоткрывает операционный тред поддержки.
        $this->conversations->recordMessage($user, $message, $message->created_at);

        return $message;
    }

    /** Сохранить входящее и сразу сформировать ответ (синхронно). */
    public function handleIncoming(User $user, string $text): void
    {
        if ($this->recordIncoming($user, $text) === null) {
            return;
        }
        $this->respond($user, trim($text));
    }

    /**
     * Сформировать ответ на уже сохранённое сообщение студента: self-service /
     * передача куратору / ИИ. Вызывается из очереди (ИИ — синхронный HTTP).
     */
    public function respond(User $user, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        // 1.5. Self-service «мои группы» — отвечаем из БД, минуя ИИ.
        if ($this->selfService->matchesGroupsIntent($text)) {
            $this->botSay($user, $this->selfService->groupsSummary($user));

            return;
        }

        // 2. Режим человека уже включён — ИИ молчит, ждём куратора.
        if (Cache::has($this->humanModeKey($user))) {
            return;
        }

        // 3. Триггер «позови куратора» — включаем режим человека на 2 часа.
        foreach (self::HUMAN_TRIGGERS as $word) {
            if (mb_stripos($text, $word) !== false) {
                Cache::put($this->humanModeKey($user), true, 7200);
                $this->botSay($user, '🙏 Понял вас. Передал вопрос живому куратору — ответ придёт сюда же, ожидайте.');

                return;
            }
        }

        // 4. Обычный ответ ИИ-куратора (тот же сервис, что у ботов).
        $answer = $this->ai->reply($user, $text);
        $this->botSay(
            $user,
            $answer ?? 'Мои чакры перегружены 🧘. Напишите «позови куратора» — и вам ответит человек.'
        );
    }

    private function botSay(User $user, string $text): void
    {
        $message = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'bot',
            'text' => $text,
            'is_read' => true,
        ]);

        $this->conversations->recordMessage($user, $message, $message->created_at);
    }
}
