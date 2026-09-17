<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Announcement;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\ScheduleAttendanceNotice;
use App\Services\AttendanceNoticeService;
use Illuminate\Http\Request;

trait ManagesStudentMiscPages
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

    /**
     * Раздел «Открытые уроки / вебинары» — доступен любому залогиненному студенту.
     * Показывает все уроки с is_free=true (независимо от покупок и групп).
     */
    public function openLessons()
    {
        $lessons = Lesson::free()
            ->where('is_published', true)
            ->with('course:id,title,slug')
            ->orderByDesc('lesson_date')
            ->orderByDesc('id')
            ->get();

        return view('student.open-lessons', compact('lessons'));
    }

    /**
     * H1680 — Wave 2: cabinet skill-drill strip. Links out to the existing
     * free /lila drills, DISTINCT from the FSRS review loop at /dvaram/koloda —
     * short single-item practice, no spaced-repetition scheduling here.
     * Static curated list (the drills themselves live in public/lila/, not
     * in the DB) — matches the "not FSRS" scope of this handoff.
     */
    public function skillDrills()
    {
        $drills = [
            ['family' => 'sort', 'label' => 'Гласные: долгие и краткие', 'url' => '/lila/sort/vowel-length/'],
            ['family' => 'match', 'label' => 'IAST ↔ кириллица', 'url' => '/lila/match/iast-cyrillic/'],
            ['family' => 'match', 'label' => 'Кочергина, урок 1', 'url' => '/lila/match/kochergina-l1/'],
            ['family' => 'roots', 'label' => 'Корни: топ-25', 'url' => '/lila/roots/top-25/'],
            ['family' => 'ligatures', 'label' => 'Лигатуры: топ-10', 'url' => '/lila/ligatures/top-10/'],
            ['family' => 'cloze', 'label' => 'Ранг корня: клоуз', 'url' => '/lila/cloze/root-rank/'],
        ];

        return view('student.skill-drills', compact('drills'));
    }

    public function messages()
    {
        $user = auth()->user();

        // Добавили круглые скобки () и явно указали таблицу, чтобы избежать конфликтов!
        $userGroupIds = $user->groups()->pluck('groups.id')->toArray();

        $messages = Announcement::where('is_published', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(function ($announcement) use ($userGroupIds) {
                if (empty($announcement->target_groups)) {
                    return true;
                }

                return count(array_intersect($announcement->target_groups, $userGroupIds)) > 0;
            });

        return view('student.messages', compact('messages'));
    }
}
