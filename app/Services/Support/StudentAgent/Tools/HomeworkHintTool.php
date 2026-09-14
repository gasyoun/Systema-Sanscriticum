<?php

declare(strict_types=1);

namespace App\Services\Support\StudentAgent\Tools;

use App\Models\HomeworkComment;
use App\Models\HomeworkSubmission;
use App\Models\User;
use App\Services\Bot\CuratorAi;
use App\Services\Support\SupportAnswerSuggester;

/**
 * Job 1/3 (H3231): homework hint. Templates beat LLM, same precedence as
 * {@see SupportAnswerSuggester}: a teacher's own review
 * comment on the submission (if any) IS the hint — no LLM call. Only when
 * there is no teacher comment yet does it fall back to a bounded LLM call,
 * and only the lesson/course TITLE is sent, never submission body, files, or
 * Telegram DM text (H3231 privacy fence).
 */
final class HomeworkHintTool implements StudentAgentTool
{
    public function __construct(private readonly CuratorAi $curatorAi) {}

    public function name(): string
    {
        return 'homework_hint';
    }

    public function isIrreversible(): bool
    {
        return false;
    }

    public function run(User $user, array $params): array
    {
        $submissionId = $params['submission_id'] ?? null;
        if (! is_numeric($submissionId)) {
            return ['ok' => false, 'reason' => 'missing_submission_id'];
        }

        /** @var HomeworkSubmission|null $submission */
        $submission = HomeworkSubmission::query()->with('lesson', 'course')->find((int) $submissionId);
        if ($submission === null) {
            return ['ok' => false, 'reason' => 'submission_not_found'];
        }

        if ((int) $submission->user_id !== (int) $user->id) {
            return ['ok' => false, 'reason' => 'not_owner'];
        }

        $teacherComment = $submission->comments()
            ->where('type', HomeworkComment::TYPE_REVIEW)
            ->whereIn('author_role', [HomeworkComment::ROLE_TEACHER, HomeworkComment::ROLE_ADMIN])
            ->latest('created_at')
            ->first();

        if ($teacherComment !== null && trim((string) $teacherComment->body) !== '') {
            return ['ok' => true, 'data' => ['source' => 'teacher_comment', 'hint' => $teacherComment->body]];
        }

        $topic = trim((string) ($submission->lesson?->title ?? $submission->course?->title ?? ''));
        if ($topic === '') {
            return ['ok' => false, 'reason' => 'no_topic'];
        }

        $result = $this->curatorAi->chatWithUsage([
            ['role' => 'system', 'content' => 'Ты помощник по домашним заданиям школы санскрита. Дай ОДНУ короткую '.
                'подсказку (2-3 предложения) по теме урока — направление мысли, а не '.
                'готовый ответ. Никогда не пиши решение целиком.'],
            ['role' => 'user', 'content' => 'Тема урока: '.$topic],
        ]);

        if ($result['content'] === null) {
            return ['ok' => false, 'reason' => 'llm_unavailable'];
        }

        return [
            'ok' => true,
            'data' => ['source' => 'llm', 'hint' => $result['content']],
            'tokens' => ($result['usage']['prompt_tokens'] ?? 0) + ($result['usage']['completion_tokens'] ?? 0),
        ];
    }
}
