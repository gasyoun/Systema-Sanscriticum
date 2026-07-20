<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FeedToken;
use App\Models\User;
use App\Services\Calendar\IcsFeedBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

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
}
