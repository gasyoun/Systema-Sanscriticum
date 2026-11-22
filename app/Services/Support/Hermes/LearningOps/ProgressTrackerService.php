<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\LearningOps;

use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hermes W2 роль 28 `progress-tracker` (H6103, департамент C «Учебные
 * операции», эпик E026): недельная сводка прохождения по группе — «%
 * прохождения, отставшие, еженедельная сводка куратору-агенту» (ростер
 * v1 §C28).
 *
 * Инварианты роли (реестр config/school_learning_ops.php, гейты ростера V2):
 *  - только чтение: ни одной записи — сводка не трогает ни пивот lesson_user,
 *    ни напоминания (suggester Q3: черновик дайджеста отправляет человек);
 *  - завершаемость считается ТОЛЬКО по lesson_user.is_completed — столбец
 *    lesson_views.is_completed не заполняется (H3764: метрика, севшая туда,
 *    покажет ровный ноль, неотличимый от честного);
 *  - детерминированность = ноль LLM-вызовов (Q7: квота не расходуется;
 *    Q5: персональные данные не покидают контур — текст никому не
 *    передаётся; черновик видит только человек-куратор);
 *  - без скидочной автономии (Q6): в сводке нет денег/скидок — только
 *    учебный прогресс; тон школы Q9: без стыда, отставший = «стоит
 *    подтянуть», не «провалился»;
 *  - пустая группа уроков — не молчание и не уверенный ноль:
 *    requires_human_verification с машинно-читаемой причиной (тот же
 *    честный контракт, что у H6187);
 *  - роль OFF по умолчанию: features.hermes_progress_tracker = false,
 *    включение — deploy-шаг человека (гейт волны W2 ещё не снят).
 */
final class ProgressTrackerService
{
    public const ROLE_ID = 28;

    public const ROLE_NAME = 'progress-tracker';

    /** Минимальный стаж членства, после которого «0 пройденных» становится красным флагом. */
    public const FALLING_BEHIND_MIN_DAYS = 14;

    private const PRECISION = 0;

    /**
     * @return array{ok: bool, reason?: string, role: string, role_id: int, draft?: string, requires_human_verification?: bool, verification_reason?: string|null, facts?: array<string, mixed>, confidence?: float}
     */
    public function digestForGroup(Group|int $group): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => false, 'reason' => 'disabled', 'role' => self::ROLE_NAME, 'role_id' => self::ROLE_ID];
        }

        $group = $group instanceof Group ? $group : Group::query()->findOrFail($group);

        $observedAt = now();
        $students = $group->activeUsers()->get();
        $lessons = Lesson::query()
            ->where('group_id', $group->id)
            ->where('is_published', true)
            ->orderBy('id')
            ->get(['id', 'title']);

        if ($students->isEmpty() || $lessons->isEmpty()) {
            return [
                'ok' => false,
                'reason' => $students->isEmpty() ? 'no_active_students' : 'no_published_lessons',
                'role' => self::ROLE_NAME,
                'role_id' => self::ROLE_ID,
            ];
        }

        $completedCounts = $this->completedCountsByUser($lessons, $students);
        $membershipsFrom = $this->membershipStarts($group, $students);

        $rows = [];
        $fallingBehind = [];
        foreach ($students as $student) {
            $completed = (int) ($completedCounts[$student->id] ?? 0);
            $percent = (int) round(($completed / $lessons->count()) * 100, self::PRECISION);
            $row = [
                'user_id' => $student->id,
                'name' => $student->name,
                'completed' => $completed,
                'total' => $lessons->count(),
                'percent' => $percent,
                'member_since' => optional($membershipsFrom[$student->id])->toDateString(),
            ];
            if ($completed === 0 && $this->tenuredEnough($membershipsFrom[$student->id] ?? null, $observedAt)) {
                $row['red_flag'] = 'no_completed_lessons';
                $fallingBehind[] = $row;
            }
            $rows[] = $row;
        }

        $avgPercent = (int) round(collect($rows)->avg('percent'), self::PRECISION);
        $verificationReason = null;

        return [
            'ok' => true,
            'role' => self::ROLE_NAME,
            'role_id' => self::ROLE_ID,
            'draft' => $this->composeDraft($observedAt, $group, $rows, $fallingBehind, $avgPercent, $lessons->count()),
            'requires_human_verification' => $verificationReason !== null,
            'verification_reason' => $verificationReason,
            'facts' => [
                'observation_time' => $observedAt->toIso8601String(),
                'canonical_source' => 'lesson_user.is_completed (H3764)',
                'group_id' => $group->id,
                'group_name' => $group->name,
                'active_students' => $students->count(),
                'published_lessons' => $lessons->count(),
                'average_percent' => $avgPercent,
                'students' => $rows,
                'falling_behind_user_ids' => array_map(static fn (array $r): int => $r['user_id'], $fallingBehind),
            ],
            'confidence' => 0.9,
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) config('features.hermes_progress_tracker', false);
    }

    /**
     * Пройденные уроки студентов из палитры группы: user_id => completed count.
     * Только is_completed=true пивот-строки (User::completedLessons контракт).
     *
     * @param  Collection<int, Lesson>  $lessons
     * @param  Collection<int, User>  $students
     * @return array<int, int>
     */
    private function completedCountsByUser(Collection $lessons, Collection $students): array
    {
        $rows = DB::table('lesson_user')
            ->whereIn('lesson_id', $lessons->pluck('id')->all())
            ->whereIn('user_id', $students->pluck('id')->all())
            ->where('is_completed', true)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) AS completed')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->user_id] = (int) $row->completed;
        }

        return $counts;
    }

    /**
     * Дата вступления студента в группу (pivot created_at): user_id => Carbon|null.
     *
     * @param  Collection<int, User>  $students
     * @return array<int, Carbon|null>
     */
    private function membershipStarts(Group $group, Collection $students): array
    {
        $rows = DB::table('group_user')
            ->where('group_id', $group->id)
            ->whereIn('user_id', $students->pluck('id')->all())
            ->get(['user_id', 'created_at']);

        $starts = [];
        foreach ($rows as $row) {
            $starts[(int) $row->user_id] = $row->created_at ? Carbon::parse($row->created_at) : null;
        }

        return $starts;
    }

    private function tenuredEnough(?Carbon $memberSince, Carbon $observedAt): bool
    {
        return $memberSince !== null && $memberSince->diffInDays($observedAt) >= self::FALLING_BEHIND_MIN_DAYS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $fallingBehind
     */
    private function composeDraft(Carbon $observedAt, Group $group, array $rows, array $fallingBehind, int $avgPercent, int $totalLessons): string
    {
        $lines = [
            'Сводка прогресса группы «'.$group->name.'» на '.$observedAt->format('d.m.Y').':',
            "Опубликованных уроков: {$totalLessons}, активных студентов: ".count($rows).'.',
            "Средний прогресс по группе: {$avgPercent}%.",
        ];

        if ($fallingBehind !== []) {
            $lines[] = 'Стоит подтянуть (нет пройденных уроков при стаже от '.self::FALLING_BEHIND_MIN_DAYS.' дней):';
            foreach ($fallingBehind as $row) {
                $lines[] = '· '.$row['name'].' — 0 из '.$row['total'].', в группе с '.$row['member_since'];
            }
        } else {
            $lines[] = 'Отстающих нет — группа движется ровно.';
        }

        $lines[] = 'Полный разбор по каждому студенту — в панели группы.';

        return implode("\n", $lines);
    }
}
