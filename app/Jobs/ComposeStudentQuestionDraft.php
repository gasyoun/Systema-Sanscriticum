<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TelegramStudentQuestion;
use App\Services\Bot\CuratorAi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * H8527: CuratorAi-черновик ответа куратору группы на вопрос стопочки
 * (SupportAnswerSuggester-паттерн P1: черновик ТОЛЬКО хранится, ничего
 * не отправляет). PII-маскировку перед внешним провайдером делает сам
 * CuratorAi::chatWithUsage (fail-closed). Сбой → state=failed, молча.
 */
class ComposeStudentQuestionDraft implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $questionId) {}

    public function handle(CuratorAi $curatorAi): void
    {
        $question = TelegramStudentQuestion::query()->find($this->questionId);
        if ($question === null
            || $question->kind !== TelegramStudentQuestion::KIND_QUESTION
            || $question->curator_draft_state === TelegramStudentQuestion::DRAFT_STATE_GENERATED) {
            return;
        }

        if (! (bool) config('services.telegram_zapisi.student_question_ai_draft', true)
            || empty(config('services.openrouter.api_key'))) {
            $question->curator_draft_state = TelegramStudentQuestion::DRAFT_STATE_OFF;
            $question->save();

            return;
        }

        $messages = [
            ['role' => 'system', 'content' => 'Ты помощник куратора группы курсов санскрита. Участник учебного чата задал вопрос, отвечая на пост бота. Напиши КУРАТОРСКИЙ черновик ответа: 1-3 предложения, по-русски, вежливо, без обещаний изменить расписание и без денежных обещаний — расписание меняет только преподаватель, предложения по переносу куратор передаёт ему. Не придумывай фактов, которых нет в контексте.'],
            ['role' => 'user', 'content' => $this->context($question)."\n\nВопрос участника: ".$question->question_text],
        ];

        $result = $curatorAi->chatWithUsage($messages);
        $content = $result['content'];

        if ($content === null || trim($content) === '') {
            $question->curator_draft_state = TelegramStudentQuestion::DRAFT_STATE_FAILED;
            $question->save();

            Log::warning('ComposeStudentQuestionDraft: no draft produced', [
                'question_id' => $question->id,
                'model' => $result['model'],
            ]);

            return;
        }

        $question->curator_draft = $content;
        $question->curator_draft_model = $result['model'];
        $question->curator_draft_state = TelegramStudentQuestion::DRAFT_STATE_GENERATED;
        $question->save();
    }

    private function context(TelegramStudentQuestion $question): string
    {
        $parts = [];
        if ($question->author_name !== null && $question->author_name !== '') {
            $parts[] = 'Автор: '.$question->author_name;
        }
        if ($question->replied_post_excerpt !== null && $question->replied_post_excerpt !== '') {
            $parts[] = 'Текст поста бота, на который он отвечает: '.mb_substr($question->replied_post_excerpt, 0, 300);
        }

        return $parts === [] ? 'Контекста нет.' : implode(".\n", $parts);
    }
}
