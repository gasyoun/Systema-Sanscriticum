<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AccessAttempt;
use App\Services\Access\AccessAttemptLogger;
use Illuminate\Auth\Events\Failed;

/**
 * Пишет неудачные попытки входа в ленту «проблем со входом» (H849). Событие
 * Failed срабатывает на каждый неуспешный Auth::attempt — и на /login, и на
 * /shop/login. IP/UA берём из текущего запроса (слушатель работает синхронно
 * внутри запроса логина).
 */
class LogFailedAuthentication
{
    public function __construct(private AccessAttemptLogger $logger) {}

    public function handle(Failed $event): void
    {
        $email = null;
        if (is_array($event->credentials)) {
            $email = $event->credentials['email'] ?? null;
        }

        $request = request();

        $this->logger->record(
            AccessAttempt::KIND_FAILED_LOGIN,
            email: is_string($email) ? $email : null,
            userId: $event->user?->getAuthIdentifier(),
            ip: $request?->ip(),
            userAgent: $request?->userAgent(),
        );
    }
}
