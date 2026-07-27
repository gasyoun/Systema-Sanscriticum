<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Activity\ActivityTracker;
use App\Services\Games\GamesOnboardingImporter;
use App\Services\OnboardingNotifier;
use Illuminate\Auth\Events\Login;

final class UserLoginListener
{
    public function __construct(
        private readonly ActivityTracker $tracker,
        private readonly OnboardingNotifier $onboarding,
        private readonly GamesOnboardingImporter $gamesOnboarding,
    ) {}

    public function handle(Login $event): void
    {
        // Трекаем только реальных пользователей нашей системы
        if (! $event->user instanceof User) {
            return;
        }

        // Не трекаем админов, заходящих в Filament — это не студенты.
        // Если позже понадобится трекать и админов — убрать этот return.
        if ($event->user->is_admin) {
            return;
        }

        // login_count берём ДО handleLogin (там он инкрементится). 0 → это
        // самый первый вход студента; больше пинг не сработает.
        $isFirstLogin = ((int) $event->user->login_count) === 0;

        $this->tracker->handleLogin($event->user, request());

        if ($isFirstLogin) {
            $this->onboarding->firstLogin($event->user);
            $this->importGamesOnboarding($event->user);
        }
    }

    /**
     * H1680 — "on register, import seen lemmas" (PLAN D8). Merges any
     * anonymous game_events from this browser onto the new user (the
     * login form carries anon_id from localStorage — see auth/login.blade.php),
     * then imports up to 20 cards from whichever free-play packs map to a
     * canonical SRS deck. Both steps are no-ops (return early / import 0)
     * when there is no play history — never blocks or fails login.
     */
    private function importGamesOnboarding(User $user): void
    {
        $anonId = request()->input('anon_id');
        $this->gamesOnboarding->mergeGuestEvents($user, is_string($anonId) ? $anonId : null);

        $imported = $this->gamesOnboarding->importForUser($user);
        if ($imported > 0) {
            session()->flash('games_onboarding_status', $this->toastText($imported));
        }
    }

    private function toastText(int $n): string
    {
        return 'Добавили '.$n.' '.$this->pluralWord($n).' в повторения';
    }

    /** RU plural: 1 слово / 2-4 слова / 5+ слов (и все формы на 11-14). */
    private function pluralWord(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'слово';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'слова';
        }

        return 'слов';
    }
}
