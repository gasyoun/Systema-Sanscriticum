<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\LearningOps;

use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Hermes W2 роль 27 `deadline-butler` (H6103, департамент C «Учебные
 * операции», эпик E026): мягкие напоминания о домашних заданиях по тону
 * школы — «вежливо, без стыда» (ростер v1 §C27).
 *
 * Инварианты роли (реестр config/school_learning_ops.php, гейты ростера V2):
 *  - только чтение: сервис не делает ни одной записи — ни в уроки, ни в
 *    домашки, ни в напоминания (suggester Q3: черновик отправляет человек
 *    через human-send H6183; первый черновик напоминания пишет только человек);
 *  - детерминированность = ноль LLM-вызовов (Q7: подписочная квота не
 *    расходуется; PII Q5 не покидает контур — текст никому не передаётся);
 *  - дедлайны честные: в таблице lessons нет «даты сдачи», есть только
 *    закрытие приёма (homework_closed_at) — черновик НЕ выдумывает
 *    сроков и формулирует «приём открыт / приём закрыт»;
 *  - без скидочной автономии (Q6): в черновике нет ни слова о деньгах,
 *    скидках и рассрочках — только учебные действия;
 *  - тон школы (Q9): без канцелярита и давления; обязательный
 *    litredaktor-пасс отменён (решение 05-10);
 *  - роль OFF по умолчанию: features.hermes_deadline_butler = false,
 *    включение — deploy-шаг человека (гейт волны W2 ещё не снят).
 */
final class DeadlineButlerService
{
    public const ROLE_ID = 27;

    public const ROLE_NAME = 'deadline-butler';

    /**
     * @return array{ok: bool, reason?: string, role: string, role_id: int, draft?: string, requires_human_verification?: bool, verification_reason?: string|null, facts?: array<string, mixed>, confidence?: float}
     */
    public function draftFor(User $user): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => false, 'reason' => 'disabled', 'role' => self::ROLE_NAME, 'role_id' => self::ROLE_ID];
        }

        $groups = $user->activeGroups()->get();
        if ($groups->isEmpty()) {
            return ['ok' => false, 'reason' => 'no_active_groups', 'role' => self::ROLE_NAME, 'role_id' => self::ROLE_ID];
        }

        $observedAt = now();
        $lessons = $this->homeworkLessons($groups);
        $submissions = $this->latestSubmissions($user);

        $awaiting = [];
        $onReview = [];
        $closedUnsubmitted = [];

        foreach ($lessons as $lesson) {
            $status = $submissions[$lesson->id] ?? null;

            if ($status === HomeworkSubmission::STATUS_ACCEPTED) {
                continue;
            }

            if ($lesson->homeworkOpenFor($user)) {
                if ($status === HomeworkSubmission::STATUS_SUBMITTED) {
                    $onReview[] = $lesson;
                } else {
                    // null (не начинал), draft (черновик), needs_revision (доработка).
                    $awaiting[] = ['lesson' => $lesson, 'status' => $status];
                }
            } elseif ($status === null || $status === HomeworkSubmission::STATUS_DRAFT) {
                // Приём закрыт, сдать не успели — это вопрос человека, не давления на студента.
                $closedUnsubmitted[] = $lesson;
            }
        }

        $draft = $this->composeDraft($observedAt, $awaiting, $onReview);
        $verificationReason = $closedUnsubmitted === [] ? null : 'closed_homework_without_submission';

        return [
            'ok' => true,
            'role' => self::ROLE_NAME,
            'role_id' => self::ROLE_ID,
            'draft' => $draft,
            'requires_human_verification' => $verificationReason !== null,
            'verification_reason' => $verificationReason,
            'facts' => [
                'observation_time' => $observedAt->toIso8601String(),
                'canonical_source' => 'lessons.homework_* + homework_submissions',
                'group_ids' => $groups->pluck('id')->all(),
                'awaiting' => array_map(static fn (array $a): array => [
                    'lesson_id' => $a['lesson']->id,
                    'title' => $a['lesson']->title,
                    'submission_status' => $a['status'] ?? 'not_started',
                ], $awaiting),
                'on_review' => array_map(static fn (Lesson $l): array => [
                    'lesson_id' => $l->id,
                    'title' => $l->title,
                ], $onReview),
                'closed_unsubmitted' => array_map(static fn (Lesson $l): int => $l->id, $closedUnsubmitted),
            ],
            'confidence' => 0.9,
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) config('features.hermes_deadline_butler', false);
    }

    /**
     * Опубликованные уроки групп студента с включённой домашкой.
     *
     * @param  Collection<int, \App\Models\Group>  $groups
     * @return Collection<int, Lesson>
     */
    private function homeworkLessons(Collection $groups): Collection
    {
        return Lesson::query()
            ->whereIn('group_id', $groups->pluck('id')->all())
            ->where('is_published', true)
            ->where('homework_enabled', true)
            ->whereNotNull('homework_prompt')
            ->orderBy('id')
            ->get();
    }

    /**
     * Последняя по активности домашка студента на урок: lesson_id => status.
     *
     * @return array<int, string|null>
     */
    private function latestSubmissions(User $user): array
    {
        $rows = HomeworkSubmission::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity_at')
            ->get(['lesson_id', 'status']);

        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row->lesson_id] ??= $row->status;
        }

        return $latest;
    }

    /**
     * @param  list<array{lesson: Lesson, status: string|null}>  $awaiting
     * @param  list<Lesson>  $onReview
     */
    private function composeDraft(Carbon $observedAt, array $awaiting, array $onReview): string
    {
        $lines = ['По домашним заданиям на '.$observedAt->format('d.m.Y').':'];

        foreach ($awaiting as $item) {
            $title = $item['lesson']->title ?: ('урок #'.$item['lesson']->id);
            $lines[] = match ($item['status']) {
                HomeworkSubmission::STATUS_NEEDS_REVISION => "· «{$title}» — домашка ждёт доработки после проверки",
                HomeworkSubmission::STATUS_DRAFT => "· «{$title}» — черновик домашки уже начат, можно сдвинуться и сдать",
                default => "· «{$title}» — домашка ждёт вас, сдать можно в кабинете",
            };
        }

        if ($onReview !== []) {
            $titles = implode(', ', array_map(static fn (Lesson $l): string => '«'.($l->title ?: 'урок #'.$l->id).'»', $onReview));
            $lines[] = "На проверке у преподавателя: {$titles}.";
        }

        if ($awaiting === [] && $onReview === []) {
            $lines[] = 'Открытых домашних заданий нет — всё сдано, новых работ пока не появилось.';
        } else {
            $lines[] = 'Если что-то не открывается или нужен перенос — просто напишите нам, разберёмся.';
        }

        return implode("\n", $lines);
    }
}
