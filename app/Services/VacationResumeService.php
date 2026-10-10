<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendMessengerAlerts;
use App\Jobs\SendTelegramChatMessageJob;
use App\Mail\VacationResumeMail;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Models\VacationResumeNotice;
use App\Services\Access\TelegramAdminNotifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * H6343: каскад анонса возобновления занятий после каникул.
 *
 * MG-контракт (грилл 10-10-2026, группа 89 «Грамматика по Кочергиной гр.57»):
 *  - волны T−7д и T−1д в 20:00 МСК, каналы: личные ЛС (Telegram/VK) + чат
 *    группы + email;
 *  - гибридный гейт: волна уходит, только если опорное занятие — первая живая
 *    строка после паузы ≥ 21 дня ИЛИ флаг каникул группы снят на этой неделе;
 *  - текст: диапазон дат + количество занятий + ссылка на календарь кабинета +
 *    предложение ICS-подписки;
 *  - кнопка куратора в Filament (GroupResource) — ручная волна в любой момент;
 *  - явка-алерт куратору при явке <50% на первом занятии после паузы.
 *
 * Дедуп — таблица vacation_resume_notices: один ряд на (группа, фаза, занятие),
 * поэтому ежедневный scheduler-запуск безопасно идемпотентен.
 */
class VacationResumeService
{
    /** Пауза, начиная с которой возобновление считается «после каникул» (дней). */
    public const MIN_PAUSE_DAYS = 21;

    /** Порог явки на первом занятии после паузы, ниже которого куратору уходит алерт (%). */
    public const ATTENDANCE_ALERT_THRESHOLD = 50;

    public const STATUS_SENT = 'sent';

    public const STATUS_DEDUP = 'dedup';

    public const STATUS_NO_RESUME = 'no_resume';

    public const STATUS_NO_AUDIENCE = 'no_audience';

    public function __construct(
        private readonly TelegramAdminNotifier $adminNotifier,
    ) {}

    /** Первая будущая живая строка расписания группы (soft-deleted отфильтрованы). */
    public function resumeRow(Group $group): ?Schedule
    {
        return $group->schedules()
            ->where('start', '>', now())
            ->orderBy('start')
            ->first();
    }

    /**
     * Пауза перед опорным занятием в днях: от последней живой строки до него.
     * null — предыдущих строк нет (сравнивать не с чем).
     */
    public function pauseDaysBefore(Group $group, Schedule $resume): ?int
    {
        $previous = $group->schedules()
            ->where('start', '<', $resume->start)
            ->orderByDesc('start')
            ->first();

        if ($previous === null) {
            return null;
        }

        return (int) $previous->start->copy()->startOfDay()->diffInDays($resume->start->copy()->startOfDay());
    }

    /**
     * Какая волна должна уйти группе сегодня (вызывается ежедневно в 20:00 МСК).
     * Возвращает ['phase' => t7|t1, 'resume' => Schedule] или null.
     *
     * @return array{phase: string, resume: Schedule}|null
     */
    public function duePhase(Group $group, CarbonInterface $today): ?array
    {
        if ($group->status === 'archived') {
            return null;
        }

        $resume = $this->resumeRow($group);
        if ($resume === null) {
            return null;
        }

        $pause = $this->pauseDaysBefore($group, $resume);
        $afterLongPause = $pause !== null && $pause >= self::MIN_PAUSE_DAYS;

        // Снятие флага каникул на этой неделе: метку ставит Group::booted при
        // переходе is_on_vacation true→false (см. vacation_cleared_at).
        $flagClearedThisWeek = ! $group->is_on_vacation
            && $group->vacation_cleared_at !== null
            && $group->vacation_cleared_at->greaterThan($today->copy()->subDays(7));

        if (! $afterLongPause && ! $flagClearedThisWeek) {
            return null;
        }

        $daysAhead = (int) $today->copy()->startOfDay()->diffInDays($resume->start->copy()->startOfDay());

        if ($daysAhead === 7) {
            return ['phase' => VacationResumeNotice::PHASE_T7, 'resume' => $resume];
        }

        if ($daysAhead === 1) {
            return ['phase' => VacationResumeNotice::PHASE_T1, 'resume' => $resume];
        }

        return null;
    }

    /** Текст анонса: диапазон дат + количество занятий + календарь + ICS. */
    public function resumeText(Group $group, Schedule $resume, ?User $forUser = null): string
    {
        $stats = $this->classStats($group, $resume);
        $first = $resume->start;
        $dayName = $first->copy()->locale('ru')->getTranslatedDayName();

        $lines = [
            '🎓 <b>Занятия возобновляются!</b>',
            '',
            'Намасте! 🙏 Группа «'.e($group->name).'» возвращается с каникул.',
            '',
            '📅 Первое занятие: <b>'.$dayName.', '.$first->format('d.m.Y').' в '.$first->format('H:i').'</b> (МСК)',
        ];

        if ($stats['count'] > 1) {
            $lines[] = '📊 Впереди <b>'.$stats['count'].' занятий</b>: '.$stats['first_date'].' — '.$stats['last_date'];
        }

        $lines[] = '';
        $lines[] = "🗓 Календарь занятий — в личном кабинете: <a href='".route('student.calendar')."'>открыть календарь</a>";

        if ($forUser !== null) {
            $ics = $this->icsUrlFor($forUser);
            $lines[] = $ics !== null
                ? "📥 Подписка на расписание (ICS): <a href='".$ics."'>добавить в свой календарь</a>"
                : '📥 ICS-подписку можно включить в кабинете: «Календарь» → «Подписка на календарь».';
        } else {
            $lines[] = '📥 ICS-подписку можно включить в личном кабинете — расписание само появится в вашем календаре.';
        }

        return implode("\n", $lines);
    }

    /**
     * Разослать одну волну по всем каналам. Идемпотентно по таблице
     * vacation_resume_notices. $dryRun=true — только посчитать.
     *
     * @return array{status: string, recipients: int, channels: list<string>}
     */
    public function announce(Group $group, string $phase, Schedule $resume, bool $dryRun = false): array
    {
        $already = VacationResumeNotice::query()
            ->where('group_id', $group->id)
            ->where('phase', $phase)
            ->where('schedule_id', $resume->id)
            ->exists();

        if ($already) {
            return ['status' => self::STATUS_DEDUP, 'recipients' => 0, 'channels' => []];
        }

        $roster = ClassRoster::query($resume)?->get();
        $chatId = filled($group->telegram_chat_id) ? (string) $group->telegram_chat_id : null;

        if (($roster === null || $roster->isEmpty()) && $chatId === null) {
            return ['status' => self::STATUS_NO_AUDIENCE, 'recipients' => 0, 'channels' => []];
        }

        $channels = [];
        $recipients = 0;
        $groupText = $this->resumeText($group, $resume);

        foreach ($roster ?? collect() as $user) {
            /** @var User $user */
            SendMessengerAlerts::dispatch($user, $groupText);
            $recipients++;

            if (filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($user->email)->queue(new VacationResumeMail($group, $this->resumeText($group, $resume, $user)));
            }
        }

        if ($roster !== null && $roster->isNotEmpty()) {
            $channels[] = 'dm';
            $channels[] = 'email';
        }

        if ($chatId !== null) {
            SendTelegramChatMessageJob::dispatch($chatId, $groupText);
            $channels[] = 'group_chat';
            $recipients++;
        }

        if ($dryRun) {
            return ['status' => self::STATUS_SENT, 'recipients' => $recipients, 'channels' => $channels];
        }

        VacationResumeNotice::create([
            'group_id' => $group->id,
            'phase' => $phase,
            'schedule_id' => $resume->id,
            'recipients' => $recipients,
            'sent_at' => now(),
        ]);

        Log::info('VacationResume: анонс возобновления разослан', [
            'group_id' => $group->id,
            'phase' => $phase,
            'resume_schedule_id' => $resume->id,
            'resume_start' => $resume->start->toDateTimeString(),
            'recipients' => $recipients,
            'channels' => $channels,
        ]);

        return ['status' => self::STATUS_SENT, 'recipients' => $recipients, 'channels' => $channels];
    }

    /** Ручная волна куратора (кнопка в Filament): в любой момент, дедуп на строку возобновления. */
    public function announceManual(Group $group): array
    {
        $resume = $this->resumeRow($group);
        if ($resume === null) {
            return ['status' => self::STATUS_NO_RESUME, 'recipients' => 0, 'channels' => []];
        }

        return $this->announce($group, VacationResumeNotice::PHASE_MANUAL, $resume);
    }

    /**
     * Явка-алерт: первый день после паузы ≥21 дня уже прошёл, явка <50% —
     * пинг кураторскому чату и админам. Дедуп — vacation_resume_notices.
     */
    public function attendanceAlerts(bool $dryRun = false): int
    {
        $sent = 0;

        $groups = Group::query()
            ->where('status', '!=', 'archived')
            ->whereHas('schedules', fn ($q) => $q->where('start', '>', now()->subDays(3)))
            ->with('schedules')
            ->get();

        foreach ($groups as $group) {
            // Опорное занятие — то, которое уже началось/закончилось (не будущая строка).
            $first = $group->schedules()
                ->where('start', '<=', now())
                ->orderByDesc('start')
                ->get()
                ->first(fn (Schedule $row): bool => $row->start->greaterThan(now()->subDays(3)));

            if ($first === null) {
                continue;
            }

            $pause = $this->pauseDaysBefore($group, $first);
            if ($pause === null || $pause < self::MIN_PAUSE_DAYS) {
                continue;
            }

            if (VacationResumeNotice::query()
                ->where('group_id', $group->id)
                ->where('phase', VacationResumeNotice::PHASE_ATTENDANCE)
                ->where('schedule_id', $first->id)
                ->exists()) {
                continue;
            }

            $summary = app(ClassAttendanceService::class)->forSchedule($first)['summary'];
            $expected = (int) $summary['expected'];
            if ($expected === 0) {
                continue;
            }

            $came = (int) $summary['present'] + (int) $summary['clicked'];
            $percent = (int) round($came / $expected * 100);
            if ($percent >= self::ATTENDANCE_ALERT_THRESHOLD) {
                continue;
            }

            $text = '📉 <b>Низкая явка после каникул</b>'."\n\n"
                .'Группа «'.e($group->name).'» вернулась '.$first->start->format('d.m.Y').', '
                .'явка '.$percent.'% ('.$came.' из '.$expected.').'
                .' Напомните группе о возобновлении и доработайте неявившихся (win-back — гайд куратора, раздел «Каникулы групп»).';

            if (! $dryRun) {
                $chatId = (string) (config('services.telegram.curators_chat_id') ?? '');
                if ($chatId !== '') {
                    SendTelegramChatMessageJob::dispatch($chatId, $text);
                }
                $this->adminNotifier->notifyAdmins($text);

                VacationResumeNotice::create([
                    'group_id' => $group->id,
                    'phase' => VacationResumeNotice::PHASE_ATTENDANCE,
                    'schedule_id' => $first->id,
                    'recipients' => $came,
                    'sent_at' => now(),
                ]);

                Log::info('VacationResume: явка-алерт после паузы', [
                    'group_id' => $group->id,
                    'schedule_id' => $first->id,
                    'percent' => $percent,
                    'expected' => $expected,
                    'came' => $came,
                ]);
            }

            $sent++;
        }

        return $sent;
    }

    /**
     * Диапазон будущих занятий группы от опорной строки: count/first/last.
     *
     * @return array{count: int, first_date: string, last_date: string}
     */
    public function classStats(Group $group, Schedule $resume): array
    {
        $rows = $group->schedules()
            ->where('start', '>=', $resume->start)
            ->orderBy('start')
            ->get();

        if ($rows->isEmpty()) {
            return ['count' => 0, 'first_date' => '', 'last_date' => ''];
        }

        return [
            'count' => $rows->count(),
            'first_date' => $rows->first()->start->format('d.m.Y'),
            'last_date' => $rows->last()->start->format('d.m.Y'),
        ];
    }

    /** Персональная ICS-ссылка студента (токен лениво создаётся, как в кабинете). */
    public function icsUrlFor(User $user): ?string
    {
        $token = $user->calendarFeedToken()->token;

        return filled($token) ? route('student.calendar.feed', ['user' => $user->id, 'token' => $token]) : null;
    }
}
