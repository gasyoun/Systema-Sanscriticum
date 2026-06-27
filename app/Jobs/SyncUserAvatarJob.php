<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Avatars\AvatarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Точечный добор аватарки студента (при привязке TG/VK-бота).
 * Идёт в очередь, чтобы не тормозить ответ вебхука.
 */
class SyncUserAvatarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [15, 60, 300];
    }

    public function __construct(
        public readonly int $userId,
    ) {}

    public function handle(AvatarSyncService $avatars): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $avatars->sync($user);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('SyncUserAvatarJob failed permanently', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
