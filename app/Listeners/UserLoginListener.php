<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Activity\ActivityTracker;
use Illuminate\Auth\Events\Login;

final class UserLoginListener
{
    public function __construct(
        private readonly ActivityTracker $tracker,
    ) {}

    public function handle(Login $event): void
    {
        // Трекаем только реальных пользователей нашей системы
        if (!$event->user instanceof User) {
            return;
        }

        // Не трекаем админов, заходящих в Filament — это не студенты.
        // Если позже понадобится трекать и админов — убрать этот return.
        if ($event->user->is_admin) {
            return;
        }

        $this->tracker->handleLogin($event->user, request());
    }
}