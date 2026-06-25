<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\StudentChatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ответ ИИ-куратора на сообщение студента из веб-чата кабинета. В очереди, потому
 * что обращение к OpenRouter синхронное (до 45 с) — нельзя блокировать запрос.
 * Само сообщение студента уже сохранено в момент отправки (StudentChat::send).
 */
class ProcessStudentChatReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $userId, public string $text) {}

    public function handle(StudentChatService $chat): void
    {
        $user = User::find($this->userId);
        if ($user) {
            $chat->respond($user, $this->text);
        }
    }
}
