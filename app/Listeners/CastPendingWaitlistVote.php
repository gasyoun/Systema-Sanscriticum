<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Http\Controllers\Api\PublicWaitlistController;
use App\Models\CourseWaitlistItem;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Гость нажал «Намерен участвовать» на /online/zhdun → голос лёг в сессию
 * (PublicWaitlistController::vote). Здесь, на любом входе — пароль,
 * регистрация, соцсети, — засчитываем его и ставим флеш для зелёного
 * уведомления «Спасибо, ваш голос учтён!».
 */
class CastPendingWaitlistVote
{
    public function handle(Login $event): void
    {
        if (! config('features.waitlist_voting', false) || ! app()->bound('request')) {
            return;
        }

        $request = request();
        if (! $request->hasSession() || ! $event->user instanceof User) {
            return;
        }

        $pending = $request->session()->pull(PublicWaitlistController::PENDING_VOTE_SESSION_KEY);
        if (! is_array($pending) || ! is_string($pending['slug'] ?? null)) {
            return;
        }

        $item = CourseWaitlistItem::query()
            ->where('slug', $pending['slug'])
            ->where('is_listed', true)
            ->first();

        if ($item === null) {
            return;
        }

        $item->castVoteBy($event->user, $pending['slot_preference'] ?? null);
        $request->session()->flash(PublicWaitlistController::VOTED_FLASH_KEY, true);
    }
}
