<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Consent;
use App\Models\LessonAiEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Единственная точка записи журнала ИИ-помощника занятия.
 *
 * Правило хранения текстов (решение 24-11-2026): вопрос, выделенный фрагмент и
 * ответ сохраняются ТОЛЬКО у тестировщика (`users.lesson_ai_tester`) с
 * действующим согласием `ai_test` — последняя строка consents по этому типу
 * должна быть «given». У всех остальных (админ, случайный студент при включенном
 * флаге features.lesson_ai) остаются счетчики: задача, статус, время, модель.
 *
 * Заметки к занятию не сохраняются текстом никогда — только длина
 * (`notes_chars`): это личный черновик ученика, а для разбора «конспекта из
 * заметок» хватает факта и объёма.
 *
 * Запись не должна ронять ответ ученику: ошибка журнала логируется и
 * проглатывается, как в SupportAiReplyEvent и ConsentRecorder.
 */
class LessonAiEventLog
{
    /**
     * @param  array{
     *     question?: string|null,
     *     selection?: string|null,
     *     answer_md?: string|null,
     *     notes_chars?: int|null,
     *     source?: string,
     *     error_code?: string|null,
     *     model?: string|null,
     *     latency_ms?: int|null,
     *     tokens_in?: int|null,
     *     tokens_out?: int|null
     * }  $data
     */
    public function record(int $userId, ?int $lessonId, string $task, string $status, array $data = []): void
    {
        try {
            $user = User::query()->find($userId);
            $keepText = $user instanceof User && $this->textAllowed($user);

            LessonAiEvent::query()->create([
                'user_id' => $userId,
                'lesson_id' => $lessonId,
                'task' => Str::limit($task, 32, ''),
                'status' => $status,
                'source' => (string) ($data['source'] ?? LessonAiEvent::SOURCE_MODEL),
                'error_code' => isset($data['error_code']) ? Str::limit((string) $data['error_code'], 32, '') : null,
                'question' => $keepText && filled($data['question'] ?? null)
                    ? Str::limit((string) $data['question'], 500, '')
                    : null,
                'selection' => $keepText && filled($data['selection'] ?? null)
                    ? Str::limit((string) $data['selection'], 1000, '')
                    : null,
                'answer_md' => $keepText ? ($data['answer_md'] ?? null) : null,
                'notes_chars' => isset($data['notes_chars']) ? (int) $data['notes_chars'] : null,
                'model' => isset($data['model']) ? Str::limit((string) $data['model'], 64, '') : null,
                'latency_ms' => isset($data['latency_ms']) ? max(0, (int) $data['latency_ms']) : null,
                'tokens_in' => isset($data['tokens_in']) ? (int) $data['tokens_in'] : null,
                'tokens_out' => isset($data['tokens_out']) ? (int) $data['tokens_out'] : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('LessonAiEventLog: не удалось записать событие', [
                'user_id' => $userId,
                'task' => $task,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Можно ли хранить текст: тестировщик + действующее согласие ai_test. */
    public function textAllowed(User $user): bool
    {
        if (! (bool) $user->lesson_ai_tester) {
            return false;
        }

        $lastAction = Consent::query()
            ->where('user_id', $user->id)
            ->where('type', Consent::TYPE_AI_TEST)
            ->orderByDesc('id')
            ->value('action');

        return $lastAction === Consent::ACTION_GIVEN;
    }
}
