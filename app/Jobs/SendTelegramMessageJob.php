<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [15, 60, 300];
    }

    public function __construct(
        public readonly int $userId,
        public readonly string $text,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (!$user) {
            return;
        }
        $user->sendTelegramMessage($this->text);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('SendTelegramMessageJob failed permanently', [
            'user_id' => $this->userId,
            'error'   => $exception->getMessage(),
        ]);
    }
}
