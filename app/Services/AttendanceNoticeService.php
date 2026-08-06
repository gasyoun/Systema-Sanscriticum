<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Group;
use App\Models\Schedule;
use App\Models\ScheduleAttendanceNotice;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Предварительные предупреждения о занятии (H2317): кабинет + TG/VK self-service.
 * Факт посещаемости (Zoom) сюда не пишется — только намерение студента.
 */
class AttendanceNoticeService
{
    public function enabled(): bool
    {
        return (bool) config('features.attendance_notices', false);
    }

    /**
     * @return list<array{status: string, label: string, short: string, emoji: string}>
     */
    public function statusOptions(): array
    {
        return [
            [
                'status' => ScheduleAttendanceNotice::STATUS_ABSENT,
                'label' => 'Не смогу быть',
                'short' => 'Не приду',
                'emoji' => '🚫',
            ],
            [
                'status' => ScheduleAttendanceNotice::STATUS_UNCERTAIN,
                'label' => 'Не уверен (в пути / связь)',
                'short' => 'Не уверен',
                'emoji' => '❓',
            ],
            [
                'status' => ScheduleAttendanceNotice::STATUS_LATE,
                'label' => 'Опоздаю на начало',
                'short' => 'Опоздаю',
                'emoji' => '⏰',
            ],
            [
                'status' => ScheduleAttendanceNotice::STATUS_LEAVE_EARLY,
                'label' => 'Уйду раньше конца',
                'short' => 'Уйду раньше',
                'emoji' => '🚪',
            ],
        ];
    }

    public function upsert(
        User $user,
        Schedule $schedule,
        string $status,
        string $source = ScheduleAttendanceNotice::SOURCE_CABINET,
        ?string $note = null,
    ): ScheduleAttendanceNotice {
        if (! ScheduleAttendanceNotice::isValidStatus($status)) {
            throw new InvalidArgumentException("Unknown attendance notice status: {$status}");
        }

        if (! $this->userMayNoticeSchedule($user, $schedule)) {
            throw new InvalidArgumentException('Student is not on this schedule roster.');
        }

        $note = $note !== null ? mb_substr(trim($note), 0, 500) : null;
        if ($note === '') {
            $note = null;
        }

        return ScheduleAttendanceNotice::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'schedule_id' => $schedule->id,
            ],
            [
                'status' => $status,
                'source' => $source,
                'note' => $note,
            ],
        );
    }

    public function clear(User $user, Schedule $schedule): bool
    {
        return ScheduleAttendanceNotice::query()
            ->where('user_id', $user->id)
            ->where('schedule_id', $schedule->id)
            ->delete() > 0;
    }

    public function forUserSchedule(User $user, Schedule $schedule): ?ScheduleAttendanceNotice
    {
        return ScheduleAttendanceNotice::query()
            ->where('user_id', $user->id)
            ->where('schedule_id', $schedule->id)
            ->first();
    }

    /**
     * Карта user_id → notice для одного занятия (админ-ростер).
     *
     * @return Collection<int, ScheduleAttendanceNotice>
     */
    public function forScheduleKeyedByUser(Schedule $schedule): Collection
    {
        return ScheduleAttendanceNotice::query()
            ->where('schedule_id', $schedule->id)
            ->get()
            ->keyBy('user_id');
    }

    /**
     * Ближайшее ещё не закончившееся занятие студента (для бота без явного id).
     */
    public function resolveUpcomingForUser(User $user, ?int $preferGroupId = null): ?Schedule
    {
        $groupIds = $user->groups()->pluck('groups.id');
        if ($groupIds->isEmpty()) {
            return null;
        }

        $query = Schedule::query()
            ->with(['course', 'group'])
            ->where(function ($q) use ($groupIds): void {
                $q->whereIn('group_id', $groupIds)
                    ->orWhereHas('course.groups', fn ($g) => $g->whereIn('groups.id', $groupIds));
            })
            ->where(function ($q): void {
                $q->where('end', '>=', now())
                    ->orWhere(function ($q2): void {
                        $q2->whereNull('end')
                            ->where('start', '>=', now()->subHours(Schedule::DEFAULT_DURATION_HOURS));
                    });
            })
            ->orderBy('start');

        if ($preferGroupId !== null && $groupIds->contains($preferGroupId)) {
            $preferred = (clone $query)->where('group_id', $preferGroupId)->first();
            if ($preferred) {
                return $preferred;
            }
        }

        return $query->first();
    }

    /**
     * Группа по telegram_chat_id (для сообщений из учебного чата).
     */
    public function groupByTelegramChatId(string|int $chatId): ?Group
    {
        return Group::query()
            ->where('telegram_chat_id', (string) $chatId)
            ->first();
    }

    public function userMayNoticeSchedule(User $user, Schedule $schedule): bool
    {
        $groupIds = $user->groups()->pluck('groups.id');
        if ($groupIds->isEmpty()) {
            return false;
        }

        if ($schedule->group_id && $groupIds->contains($schedule->group_id)) {
            return true;
        }

        if ($schedule->course_id) {
            return $schedule->course()
                ->whereHas('groups', fn ($g) => $g->whereIn('groups.id', $groupIds))
                ->exists();
        }

        return false;
    }

    /**
     * Распознать intent в свободном тексте (TG/VK). Возвращает status или 'clear' или null.
     */
    public function matchIntent(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return null;
        }

        // Slash-команды — точные.
        if (preg_match('~^/(absent|skip|nepridu)(?:@\w+)?$~u', $t)) {
            return ScheduleAttendanceNotice::STATUS_ABSENT;
        }
        if (preg_match('~^/(late|opozdayu)(?:@\w+)?$~u', $t)) {
            return ScheduleAttendanceNotice::STATUS_LATE;
        }
        if (preg_match('~^/(early|leave_early|uydu)(?:@\w+)?$~u', $t)) {
            return ScheduleAttendanceNotice::STATUS_LEAVE_EARLY;
        }
        if (preg_match('~^/(maybe|uncertain|neuveren)(?:@\w+)?$~u', $t)) {
            return ScheduleAttendanceNotice::STATUS_UNCERTAIN;
        }
        if (preg_match('~^/(coming|budu|cancel_notice)(?:@\w+)?$~u', $t)) {
            return 'clear';
        }

        // Снятие предупреждения — раньше «не приду», чтобы «всё же приду» не
        // попало в absent по подстроке «приду».
        $clearPhrases = [
            'отменить предупреждение',
            'сниму предупреждение',
            'буду на уроке',
            'буду на занятии',
            'всё же приду',
            'все же приду',
            'всё-таки приду',
            'все-таки приду',
            'смогу прийти',
            'успею на урок',
        ];
        foreach ($clearPhrases as $phrase) {
            if (str_contains($t, $phrase)) {
                return 'clear';
            }
        }

        $absentPhrases = [
            'не смогу на урок',
            'не смогу на занятие',
            'не смогу быть на',
            'не приду на урок',
            'не приду на занятие',
            'не буду на уроке',
            'не буду на занятии',
            'пропущу урок',
            'пропущу занятие',
            'пропущу сегодня',
            'сегодня не приду',
            'сегодня не смогу',
            'меня не будет на',
        ];
        foreach ($absentPhrases as $phrase) {
            if (str_contains($t, $phrase)) {
                return ScheduleAttendanceNotice::STATUS_ABSENT;
            }
        }

        $uncertainPhrases = [
            'не уверен насчет урока',
            'не уверен насчёт урока',
            'не уверена насчет урока',
            'не уверена насчёт урока',
            'возможно не буду',
            'возможно не приду',
            'проблемная связь',
            'связь может пропасть',
            'буду в пути',
            'в пути на урок',
            'могу опоздать или не',
        ];
        foreach ($uncertainPhrases as $phrase) {
            if (str_contains($t, $phrase)) {
                return ScheduleAttendanceNotice::STATUS_UNCERTAIN;
            }
        }

        // «Опоздаю» / «уйду раньше» — короткие, но достаточно специфичные.
        if (preg_match('~опозда(ю|ем|ет)|задержусь на (урок|занятие|начало)~u', $t)) {
            return ScheduleAttendanceNotice::STATUS_LATE;
        }
        if (preg_match('~уйд(у|ём|ем) раньше|уйти раньше|раньше конца|раньше окончания~u', $t)) {
            return ScheduleAttendanceNotice::STATUS_LEAVE_EARLY;
        }

        return null;
    }

    /**
     * Self-service ответ для бота: записать intent или подсказать, если нет занятия.
     *
     * @return array{ok: bool, text: string, notice: ?ScheduleAttendanceNotice}
     */
    public function handleBotMessage(
        User $user,
        string $text,
        string $source = ScheduleAttendanceNotice::SOURCE_TELEGRAM,
        ?int $preferGroupId = null,
    ): array {
        $intent = $this->matchIntent($text);
        if ($intent === null) {
            return ['ok' => false, 'text' => '', 'notice' => null];
        }

        if (! $this->enabled()) {
            return [
                'ok' => true,
                'text' => 'Пока предупреждения о занятиях выключены. Напишите куратору в чат группы — он увидит сообщение.',
                'notice' => null,
            ];
        }

        $schedule = $this->resolveUpcomingForUser($user, $preferGroupId);
        if (! $schedule) {
            return [
                'ok' => true,
                'text' => "📅 Ближайших занятий в вашем расписании нет.\n"
                    .'Когда появится занятие, предупредить можно здесь или в личном кабинете → Расписание.',
                'notice' => null,
            ];
        }

        $when = $schedule->start->format('d.m в H:i');
        $title = e($schedule->title ?: ($schedule->course?->title ?? 'Занятие'));

        if ($intent === 'clear') {
            $this->clear($user, $schedule);

            return [
                'ok' => true,
                'text' => "✅ Предупреждение снято.\n"
                    ."Ждём вас на <b>«{$title}»</b> ({$when} МСК).",
                'notice' => null,
            ];
        }

        $notice = $this->upsert($user, $schedule, $intent, $source);
        $label = $notice->labelRu();
        $emoji = $notice->emoji();

        return [
            'ok' => true,
            'text' => "{$emoji} <b>Записал:</b> {$label}\n"
                ."Занятие: <b>«{$title}»</b> — {$when} МСК.\n\n"
                ."Куратор увидит это в ростере. Отменить: «буду на уроке» или /coming.\n"
                .'Другие варианты: /absent · /late · /early · /maybe',
            'notice' => $notice,
        ];
    }
}
