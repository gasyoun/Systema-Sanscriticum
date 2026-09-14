<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\TelegramSendGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка готового HTML-текста в произвольный Telegram chat_id основным ботом.
 * В отличие от SendTelegramMessageJob (адресует User по telegram_id), здесь
 * получатель — это chat_id напрямую (группа кураторов, канал и т.п.).
 *
 * Отправка идемпотентна (TelegramSendGuard) — см. зеркало в SendZapisiBotMessageJob.
 */
class SendTelegramChatMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $chatId,
        public readonly string $text,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [15, 60, 300];
    }

    public function handle(): void
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '' || $this->chatId === '') {
            return;
        }

        if (! TelegramSendGuard::claim($this->chatId, $this->text)) {
            Log::info('SendTelegramChatMessageJob: identical message already sent, duplicate suppressed', [
                'chat_id' => $this->chatId,
                'dedup_key' => TelegramSendGuard::key($this->chatId, $this->text),
            ]);

            return;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $this->text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $exception) {
            // Транспортный сбой (не соединились ИЛИ ответ потерян после отправки —
            // одним классом исключения не различить): ключ НЕ отпускаем, ретрай
            // подавится guard'ом. См. зеркало в SendZapisiBotMessageJob.
            Log::warning('SendTelegramChatMessageJob: transport failure, retry suppressed to avoid duplicate', [
                'chat_id' => $this->chatId,
                'error' => $exception->getMessage(),
                'dedup_key' => TelegramSendGuard::key($this->chatId, $this->text),
            ]);

            return;
        }

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            Log::warning('SendTelegramChatMessageJob: Telegram API error', [
                'chat_id' => $this->chatId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            TelegramSendGuard::release($this->chatId, $this->text);
            throw new \RuntimeException('Telegram sendMessage error: '.$response->body());
        }

        Log::info('SendTelegramChatMessageJob: message sent', [
            'chat_id' => $this->chatId,
            'length' => mb_strlen($this->text),
            'dedup_key' => TelegramSendGuard::key($this->chatId, $this->text),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('SendTelegramChatMessageJob failed permanently', [
            'chat_id' => $this->chatId,
            'error' => $exception->getMessage(),
        ]);
    }
}
