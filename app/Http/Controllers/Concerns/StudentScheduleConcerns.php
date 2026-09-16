<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Schedule;
use App\Models\ScheduleAttendanceNotice;
use App\Models\User;
use App\Services\AttendanceNoticeService;
use Illuminate\Http\Request;

trait StudentScheduleConcerns
{
    /**
     * Страница расписания (Timeline)
     */
    public function calendar()
    {
        $user = auth()->user();
        $groupIds = $user->groups->pluck('id');

        // H4434 — эффективная таймзона ученика (MG 09-09-2026). null = МСК,
        // рендер идёт как раньше; нон-МСК получают dual-display и заголовки
        // дней в своей зоне («суббота» у калифорнийца = пятница по-московски).
        $userTz = $user->effectiveTimezone();

        $upcomingEvents = Schedule::with(['course', 'group'])
            ->where(function ($query) use ($groupIds) {
                $query->whereIn('group_id', $groupIds)
                    ->orWhereNull('group_id');
            })
            ->where(function ($query) {
                // Карточка живёт, пока занятие не закончилось: по end, а для
                // записей без end — DEFAULT_DURATION_HOURS от старта (как в
                // Schedule::isLive() и в самой карточке). Иначе карточка со
                // ссылкой исчезала ровно в момент начала идущего занятия.
                $query->where('end', '>=', now())
                    ->orWhere(function ($q) {
                        $q->whereNull('end')
                            ->where('start', '>=', now()->subHours(Schedule::DEFAULT_DURATION_HOURS));
                    });
            })
            ->orderBy('start', 'asc')
            ->get();

        // H4434 — группировка по дням в зоне ученика (было: isToday/isTomorrow
        // всегда считали по московской зоне приложения).
        $groupedEvents = $upcomingEvents->groupBy(function ($event) use ($userTz) {
            $local = $event->start->timezone($userTz ?: config('app.timezone'));

            if ($local->isToday()) {
                return 'Сегодня';
            }
            if ($local->isTomorrow()) {
                return 'Завтра';
            }

            return $local->translatedFormat('d F, l');
        });

        $feedToken = $user->calendarFeedToken()->token;
        $feedUrl = route('student.calendar.feed', ['user' => $user->id, 'token' => $feedToken]);
        $webcalUrl = preg_replace('~^https?://~', 'webcal://', $feedUrl);

        // H2317 — предварительные предупреждения (не приду / опоздаю / …).
        $attendanceNoticesEnabled = (bool) config('features.attendance_notices', false);
        $myNotices = collect();
        $noticeOptions = [];
        if ($attendanceNoticesEnabled && $upcomingEvents->isNotEmpty()) {
            $myNotices = ScheduleAttendanceNotice::query()
                ->where('user_id', $user->id)
                ->whereIn('schedule_id', $upcomingEvents->pluck('id'))
                ->get()
                ->keyBy('schedule_id');
            $noticeOptions = app(AttendanceNoticeService::class)->statusOptions();
        }

        return view('student.calendar', compact(
            'groupedEvents',
            'feedUrl',
            'webcalUrl',
            'attendanceNoticesEnabled',
            'myNotices',
            'noticeOptions',
        ))->with('userTz', $userTz);
    }

    /**
     * Отвязка мессенджера от аккаунта (кнопка «Отвязать» в кабинете).
     *
     * Обнуляем id — кабинет снова покажет «Подключить», а исходящие уведомления
     * (User::sendTelegramMessage / sendVkMessage) перестанут уходить (некуда).
     * Сам чат с ботом в мессенджере при этом не закрывается — это нативный Stop.
     */
    public function disconnectMessenger(Request $request, string $channel)
    {
        $user = $request->user();

        if ($channel === 'telegram') {
            $user->update(['telegram_id' => null, 'telegram_auth_token' => null]);
        } else { // 'vk' — единственный другой вариант (ограничено в роуте whereIn)
            $user->update(['vk_id' => null, 'vk_auth_token' => null]);
        }

        return back()->with('bot_status', 'Бот отвязан — уведомления по учёбе больше не приходят. Подключить заново можно в любой момент.');
    }
}
