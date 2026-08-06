<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\ScheduleAttendanceNotice;
use App\Services\AttendanceNoticeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Личный кабинет: предупреждение о занятии (H2317).
 * Работает только при features.attendance_notices=true.
 */
class AttendanceNoticeController extends Controller
{
    public function store(Request $request, Schedule $schedule, AttendanceNoticeService $notices): RedirectResponse
    {
        if (! $notices->enabled()) {
            abort(404);
        }

        $user = $request->user();
        abort_unless($user !== null, 401);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(ScheduleAttendanceNotice::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $notices->userMayNoticeSchedule($user, $schedule)) {
            abort(403, 'Это занятие не в вашем расписании.');
        }

        $notice = $notices->upsert(
            $user,
            $schedule,
            $data['status'],
            ScheduleAttendanceNotice::SOURCE_CABINET,
            $data['note'] ?? null,
        );

        return back()->with(
            'attendance_notice_status',
            $notice->emoji().' '.$notice->labelRu().' — предупреждение сохранено.',
        );
    }

    public function destroy(Request $request, Schedule $schedule, AttendanceNoticeService $notices): RedirectResponse
    {
        if (! $notices->enabled()) {
            abort(404);
        }

        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $notices->userMayNoticeSchedule($user, $schedule)) {
            abort(403, 'Это занятие не в вашем расписании.');
        }

        $notices->clear($user, $schedule);

        return back()->with('attendance_notice_status', 'Предупреждение снято — ждём вас на занятии.');
    }
}
