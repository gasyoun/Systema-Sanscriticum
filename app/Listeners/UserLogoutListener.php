<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Activity\ActivityTracker;
use Illuminate\Auth\Events\Logout;

final class UserLogoutListener
{
    public function __construct(
        private readonly ActivityTracker $tracker,
    ) {}

    public function handle(Logout $event): void
    {
        if (!$event->user instanceof User) {
            return;
        }

        if ($event->user->is_admin) {
            return;
        }

        $this->tracker->handleLogout($event->user, request());
    }
}