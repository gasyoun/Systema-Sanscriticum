<?php

declare(strict_types=1);

namespace App\Services\Support\ScheduleKeeper;

use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Hermes W1 роль 23 `schedule-keeper` (H6187, пакет hermes-24-01a10bca):
 * актуальный ответ о расписании и отменах. Детерминированный резолвер без
 * LLM — черновик собирается ТОЛЬКО из канонического расписания (таблица
 * `schedules` + строки групп) и всегда несёт время наблюдения, чтобы человек
 * при human-send видел, насколько свежий срез он отправляет.
 *
 * Инварианты роли (ростер V2, гейты волны W1):
 *  - только чтение: сервис не делает ни одной записи в расписание/группы/
 *    пользователей — «никакой записи в расписание» из миссии H6187;
 *  - перенос/отмена различаются честно: прежнее время занятия нигде не
 *    хранится, поэтому «moved» формулируется как «изменилось — сейчас в
 *    расписании …» (тот же контракт, что у SupportAnswerFactResolver);
 *  - часовые пояса: базовое время МСК; студентам не из МСК (H4434
 *    effectiveTimezone) добавляется локальное время «(16:00 ваше)», с датой,
 *    когда локальный день отличается от московского;
 *  - устаревший источник: пустое или подозрительно дальнее расписание НЕ
 *    молчит и НЕ утверждает уверенно — ответ помечается
 *    requires_human_verification с машинно-читаемой причиной;
 *  - роль OFF по умолчанию: features.hermes_schedule_keeper = false, включение
 *    — deploy-шаг человека, не кода.
 */
final class ScheduleKeeperService
{
    public const ROLE_ID = 23;

    public const ROLE_NAME = 'schedule-keeper';

    /** Окно «изменений расписания» — то же, что у SupportAnswerFactResolver. */
    public const CHANGE_WINDOW_DAYS = 14;

    /**
     * Горизонт доверия каноническому расписанию: ближайшее занятие дальше
     * этого срока без активных каникул группы выглядит как неразобранное
     * расписание (устаревший источник) и уходит на проверку человеку.
     */
    public const HORIZON_DAYS = 45;

    private const UPCOMING_LIMIT = 3;

    /**
     * @return array{ok: bool, reason?: string, role: string, role_id: int, draft?: string, requires_human_verification?: bool, verification_reason?: string|null, facts?: array<string, mixed>, confidence?: float}
     */
    public function answerFor(User $user): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => false, 'reason' => 'disabled', 'role' => self::ROLE_NAME, 'role_id' => self::ROLE_ID];
        }

        $observedAt = now();
        $userTz = $this->safeUserTimezone($user);
        $groups = $user->activeGroups()->get();

        $upcoming = $this->upcomingClasses($groups, $observedAt);
        $changes = $this->recentChanges($groups, $observedAt);
        $vacations = $groups->filter(fn (Group $g): bool => (bool) $g->is_on_vacation)->values();

        $verificationReason = $this->stalenessReason($upcoming, $vacations, $observedAt);

        $draft = $this->composeDraft($observedAt, $upcoming, $changes, $vacations, $userTz);

        return [
            'ok' => true,
            'role' => self::ROLE_NAME,
            'role_id' => self::ROLE_ID,
            'draft' => $draft,
            'requires_human_verification' => $verificationReason !== null,
            'verification_reason' => $verificationReason,
            'facts' => [
                'observation_time' => $observedAt->toIso8601String(),
                'canonical_source' => 'schedules',
                'base_timezone' => (string) config('app.timezone', 'Europe/Moscow'),
                'user_timezone' => $userTz,
                'upcoming' => $upcoming->map(fn (Schedule $s): array => [
                    'schedule_id' => $s->id,
                    'starts_at' => optional($s->start)->toIso8601String(),
                    'starts_at_local' => $this->localTime($s->start, $userTz),
                    'title' => $s->title,
                ])->all(),
                'changes' => $changes,
                'vacation' => $vacations->map(fn (Group $g): array => [
                    'group_id' => $g->id,
                    'name' => $g->name,
                    'resume_date' => optional($g->vacation_resume_date)->toDateString(),
                ])->all(),
                'change_window_days' => self::CHANGE_WINDOW_DAYS,
                'horizon_days' => self::HORIZON_DAYS,
            ],
            'confidence' => $this->confidence($upcoming, $vacations, $verificationReason),
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) config('features.hermes_schedule_keeper', false);
    }

    /**
     * @param  Collection<int, Group>  $groups
     * @return Collection<int, Schedule>
     */
    private function upcomingClasses(Collection $groups, \DateTimeInterface $observedAt): Collection
    {
        $groupIds = $groups->pluck('id')->all();
        if ($groupIds === []) {
            return collect();
        }

        return Schedule::query()
            ->whereIn('group_id', $groupIds)
            ->whereNotNull('start')
            ->where('start', '>=', $observedAt)
            ->orderBy('start')
            ->limit(self::UPCOMING_LIMIT)
            ->get();
    }

    /**
     * Изменения будущих занятий за окно CHANGE_WINDOW_DAYS: отменённые
     * (soft-delete), добавленные и изменившиеся. Вид «перенос» таблица не
     * отличает от правки ссылки — честная формулировка в черновике.
     *
     * @param  Collection<int, Group>  $groups
     * @return list<array{schedule_id: int, kind: string, starts_at: string|null, title: string|null}>
     */
    private function recentChanges(Collection $groups, \DateTimeInterface $observedAt): array
    {
        $groupIds = $groups->pluck('id')->all();
        if ($groupIds === []) {
            return [];
        }

        $since = \Illuminate\Support\Carbon::parse($observedAt)->subDays(self::CHANGE_WINDOW_DAYS);

        /** @var Collection<int, Schedule> $touched */
        $touched = Schedule::query()
            ->withTrashed()
            ->whereIn('group_id', $groupIds)
            ->whereNotNull('start')
            ->where('start', '>=', $observedAt)
            ->where(fn ($q) => $q->where('updated_at', '>=', $since)->orWhere('deleted_at', '>=', $since))
            ->orderBy('start')
            ->limit(5)
            ->get();

        $changes = [];
        foreach ($touched as $class) {
            $kind = $this->changeKind($class, $since);
            if ($kind === null) {
                continue;
            }

            $changes[] = [
                'schedule_id' => $class->id,
                'kind' => $kind,
                'starts_at' => optional($class->start)->toIso8601String(),
                'title' => $class->title,
            ];
        }

        return $changes;
    }

    /** @return 'cancelled'|'moved'|'added'|null */
    private function changeKind(Schedule $class, \DateTimeInterface $since): ?string
    {
        if ($class->deleted_at !== null) {
            return $class->deleted_at->gte($since) ? 'cancelled' : null;
        }

        if ($class->created_at !== null && $class->created_at->gte($since)) {
            return 'added';
        }

        if ($class->updated_at !== null
            && $class->updated_at->gte($since)
            && $class->created_at !== null
            && $class->updated_at->gt($class->created_at->addSecond())
        ) {
            return 'moved';
        }

        return null;
    }

    /**
     * Устаревший источник → причина для проверки человеком. null = свежо.
     *
     * @param  Collection<int, Schedule>  $upcoming
     * @param  Collection<int, Group>  $vacations
     */
    private function stalenessReason(Collection $upcoming, Collection $vacations, \DateTimeInterface $observedAt): ?string
    {
        if ($upcoming->isEmpty()) {
            foreach ($vacations as $group) {
                if ($group->vacation_resume_date === null) {
                    return 'vacation_unknown_resume';
                }
            }

            return $vacations->isNotEmpty()
                ? 'vacation_schedule_pending'
                : 'no_upcoming_lessons';
        }

        $nearest = $upcoming->first();
        if ($nearest !== null
            && $vacations->isEmpty()
            && $nearest->start->gt(\Illuminate\Support\Carbon::parse($observedAt)->addDays(self::HORIZON_DAYS))
        ) {
            return 'next_beyond_horizon';
        }

        return null;
    }

    /**
     * @param  Collection<int, Schedule>  $upcoming
     * @param  list<array{schedule_id: int, kind: string, starts_at: string|null, title: string|null}>  $changes
     * @param  Collection<int, Group>  $vacations
     */
    private function composeDraft(
        \DateTimeInterface $observedAt,
        Collection $upcoming,
        array $changes,
        Collection $vacations,
        ?string $userTz,
    ): string {
        $lines = ['По расписанию на '.$observedAt->format('d.m.Y H:i').' (МСК):'];

        if ($upcoming->isNotEmpty()) {
            $lines[] = 'Ближайшие занятия вашей группы:';
            foreach ($upcoming as $class) {
                $lines[] = '· '.$this->renderWhen($class, $userTz).$this->renderTitle($class->title);
            }
        } else {
            $lines[] = 'ближайших занятий в расписании вашей группы сейчас нет.';
        }

        foreach ($vacations as $group) {
            $resume = $group->vacation_resume_date !== null
                ? 'возобновление — '.$group->vacation_resume_date->format('d.m.Y')
                : 'дата возобновления пока не назначена';
            $lines[] = "· Группа «{$group->name}»: каникулы, {$resume}.";
        }

        if ($changes !== []) {
            $lines[] = 'Изменения в расписании:';
            /** @var array<string, Schedule> $byId */
            $byId = Schedule::query()
                ->withTrashed()
                ->whereIn('id', array_column($changes, 'schedule_id'))
                ->get()
                ->keyBy('id')
                ->all();

            foreach ($changes as $change) {
                $class = $byId[$change['schedule_id']] ?? null;
                if ($class === null) {
                    continue;
                }

                $title = $this->renderTitleQuoted($change['title']);
                $when = $class->start?->format('d.m.Y H:i') ?? '';

                $lines[] = match ($change['kind']) {
                    'cancelled' => "· занятие{$title} {$when} (МСК) отменено",
                    'moved' => "· занятие{$title} изменилось — сейчас в расписании {$when} (МСК)",
                    default => "· добавлено занятие{$title} — {$when} (МСК)",
                };
            }
        }

        return implode("\n", $lines);
    }

    /** «18.10.2026 11:00 МСК (16:00 ваше[, 19.10])» — локальное время только не-МСК студентам. */
    private function renderWhen(Schedule $class, ?string $userTz): string
    {
        $msk = $class->start?->timezone((string) config('app.timezone', 'Europe/Moscow'));
        if ($msk === null) {
            return '';
        }

        $when = $msk->format('d.m.Y H:i').' МСК';

        $local = $this->localTime($msk, $userTz);
        if ($local !== null) {
            $when .= " ({$local['time']} ваше"
                .($local['date'] !== null ? ', '.$local['date'] : '')
                .')';
        }

        return $when;
    }

    /**
     * Локальное время студента или null (МСК-резидент / зона неизвестна).
     * Дата возвращается только когда локальный день отличается от МСК.
     *
     * @return array{time: string, date: string|null}|null
     */
    private function localTime(?\DateTimeInterface $at, ?string $userTz): ?array
    {
        if ($at === null || $userTz === null) {
            return null;
        }

        $local = \Illuminate\Support\Carbon::parse($at)->timezone($userTz);

        return [
            'time' => $local->format('H:i'),
            'date' => $local->format('d.m') !== $at->format('d.m') ? $local->format('d.m') : null,
        ];
    }

    /** Зона из H4434, если она валидна; иначе считаем неизвестной. */
    private function safeUserTimezone(User $user): ?string
    {
        $tz = $user->effectiveTimezone();
        if ($tz === null || $tz === '' || $tz === (string) config('app.timezone', 'Europe/Moscow')) {
            return null;
        }

        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : null;
    }

    private function renderTitle(?string $title): string
    {
        return trim((string) $title) !== '' ? " — {$title}" : '';
    }

    /** «Занятие 12» в строках изменений — как у SupportAnswerFactResolver. */
    private function renderTitleQuoted(?string $title): string
    {
        return trim((string) $title) !== '' ? " «{$title}»" : '';
    }

    /**
     * @param  Collection<int, Schedule>  $upcoming
     * @param  Collection<int, Group>  $vacations
     */
    private function confidence(Collection $upcoming, Collection $vacations, ?string $verificationReason): float
    {
        if ($verificationReason === 'no_upcoming_lessons') {
            return 0.4;
        }

        if ($verificationReason !== null) {
            return 0.6;
        }

        if ($upcoming->isNotEmpty() && $vacations->isEmpty()) {
            return 0.9;
        }

        return 0.75;
    }
}
