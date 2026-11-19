<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FeedToken;
use App\Models\User;
use App\Services\Calendar\IcsFeedBuilder;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Публичный (без сессии) читаемый iCal/webcal-фид расписания студента —
 * Google Calendar Phase 1, docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md.
 * Доступ контролируется токеном в URL, не auth-guard'ом.
 */
class CalendarFeedController extends Controller
{
    public function show(User $user, string $token, IcsFeedBuilder $builder): Response
    {
        $feedToken = $user->feedTokens()
            ->whereNull('revoked_at')
            ->where('token', $token)
            ->first();

        abort_if($feedToken === null, 404);

        return response($builder->build($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="schedule.ics"',
        ]);
    }

    /** Отозвать текущий токен и выдать новый (кнопка «Обновить ссылку» в кабинете). */
    public function regenerate(): RedirectResponse
    {
        $user = auth()->user();

        $user->feedTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $user->feedTokens()->create(['token' => FeedToken::generate()]);

        return back()->with('feed_token_status', 'Ссылка на фид обновлена — старая больше не работает.');
    }

    /**
     * H6347 — страница выдачи feed-токена для ПРЕПОДАВАТЕЛЯ/АДМИНА: до сих пор
     * webcal-фид выдавался только в студенческом кабинете (student.calendar).
     * Студенты (без teacher_id и не adminLike) redirected на свой кабинет.
     */
    public function teacherPage(Request $request): Response|RedirectResponse|View
    {
        $user = $request->user();

        if ($user->teacher_id === null && ! in_array($user->role, Roles::adminLike(), true)) {
            return redirect()->route('student.calendar');
        }

        $feedToken = $user->calendarFeedToken()->token;
        $feedUrl = route('student.calendar.feed', ['user' => $user->id, 'token' => $feedToken]);
        $webcalUrl = preg_replace('~^https?://~', 'webcal://', $feedUrl);

        return view('calendar.teacher-feed', compact('feedUrl', 'webcalUrl'));
    }
}
