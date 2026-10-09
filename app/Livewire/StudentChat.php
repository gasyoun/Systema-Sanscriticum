<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Jobs\ProcessStudentChatReply;
use App\Models\ChatMessage;
use App\Services\StudentChatService;
use Livewire\Component;

/**
 * Веб-чат поддержки в кабинете студента. Студент пишет сюда — сообщение сразу
 * сохраняется (видно и ему, и куратору в Helpdesk/Dialogs), ответ ИИ-куратора
 * считается в очереди и подтягивается опросом. Куратор отвечает из админки —
 * его реплики (role=curator) тоже появляются здесь.
 */
class StudentChat extends Component
{
    public string $newMessage = '';

    /** Сбой сохранения: черновик остался в поле, текст ошибки объявляется скринридеру. */
    public ?string $sendError = null;

    /** Сообщение доставлено, но ответ ИИ не встал в очередь — повторим только ответ. */
    public bool $replyPendingRetry = false;

    public function send(StudentChatService $chat): void
    {
        $text = trim($this->newMessage);
        if ($text === '') {
            return;
        }

        $this->sendError = null;

        // Сохраняем сообщение синхронно — чтобы оно мгновенно появилось в ленте.
        // Черновик очищаем только ПОСЛЕ успешной записи (H6300): сбой БД не должен
        // съедать текст студента.
        try {
            $chat->recordIncoming(auth()->user(), $text);
        } catch (\Throwable $e) {
            // Приватный черновик не должен попадать в логи: репортим только класс
            // ошибки — исходное исключение (SQL с биндингами) не цепляем.
            report(new \RuntimeException('student-chat: incoming persist failed ['.get_class($e).']'));
            $this->sendError = 'Не удалось отправить сообщение. Текст сохранён — попробуйте ещё раз.';

            return;
        }

        $this->newMessage = '';

        // Ответ ИИ — в очередь (обращение к LLM синхронное и долгое). Сообщение уже
        // сохранено: сбой очереди НЕ трогает черновик и ленту, а повтор (retryReply)
        // никогда не записывает входящее заново — дубликата не будет.
        try {
            ProcessStudentChatReply::dispatch(auth()->id(), $text);
            $this->replyPendingRetry = false;
        } catch (\Throwable $e) {
            report(new \RuntimeException('student-chat: reply dispatch failed ['.get_class($e).']'));
            $this->replyPendingRetry = true;
        }
    }

    /**
     * Повтор постановки ответа в очередь после сбоя отправки (H6300). Работает
     * только с последним входящим сообщением САМОГО пользователя: чужой диалог
     * недоступен, входящее заново не записывается.
     */
    public function retryReply(): void
    {
        $userId = auth()->id();

        $lastIncoming = ChatMessage::query()
            ->where('user_id', $userId)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->first();

        if ($lastIncoming === null) {
            $this->replyPendingRetry = false;

            return;
        }

        // Ответ уже пришёл (бот/куратор после последнего входящего) — повторять нечего.
        $answered = ChatMessage::query()
            ->where('user_id', $userId)
            ->whereIn('role', ['bot', 'curator'])
            ->where('id', '>', $lastIncoming->id)
            ->exists();
        if ($answered) {
            $this->replyPendingRetry = false;

            return;
        }

        try {
            ProcessStudentChatReply::dispatch($userId, $lastIncoming->text);
            $this->replyPendingRetry = false;
        } catch (\Throwable $e) {
            report(new \RuntimeException('student-chat: reply retry failed ['.get_class($e).']'));
            $this->replyPendingRetry = true;
        }
    }

    public function render()
    {
        $messages = ChatMessage::query()
            ->where('user_id', auth()->id())
            ->with('answeredBy:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return view('livewire.student-chat', ['messages' => $messages]);
    }
}
