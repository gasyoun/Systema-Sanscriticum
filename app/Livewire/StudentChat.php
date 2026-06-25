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

    public function send(StudentChatService $chat): void
    {
        $text = trim($this->newMessage);
        if ($text === '') {
            return;
        }

        $this->newMessage = '';

        // Сохраняем сообщение синхронно — чтобы оно мгновенно появилось в ленте.
        $chat->recordIncoming(auth()->user(), $text);

        // Ответ ИИ — в очередь (обращение к LLM синхронное и долгое).
        ProcessStudentChatReply::dispatch(auth()->id(), $text);
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
