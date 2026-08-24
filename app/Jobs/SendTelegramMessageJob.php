<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Support\TelegramSendGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Личные ЛС студенту основным ботом (чеки платежей, гранты доступа, напоминания).
 *
 * Идемпотентно (TelegramSendGuard): ретрай или повторный диспатч того же текста
 * тому же студенту подавляется. sendTelegramMessage() глотает ошибки внутрь
 * (возвращает bool), поэтому «чистый» отказ Telegram клейм не отпускает —
 * повторная отправка такого сообщения всё равно упала бы с той же ошибкой
 * (например, студент заблокировал бота); ключ в логе позволяет сбросить вручную.
 */
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
        if (! $user) {
            return;
        }

        // Без telegram_id отправка всё равно no-op — клейм не тратим.
        if (! empty($user->telegram_id)
            && ! TelegramSendGuard::claim((string) $user->telegram_id, $this->text)) {
            Log::info('SendTelegramMessageJob: identical message already sent, duplicate suppressed', [
                'user_id' => $this->userId,
                'dedup_key' => TelegramSendGuard::key((string) $user->telegram_id, $this->text),
            ]);

            return;
        }

        $user->sendTelegramMessage($this->text);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('SendTelegramMessageJob failed permanently', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
