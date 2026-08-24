<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MarketingSetting;
use App\Support\TelegramSendGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Track C (H164, D10): sends via @zapisi_ORSbot's OWN token — unlike
 * SendTelegramChatMessageJob, which uses the main user-notification bot.
 *
 * Отправка идемпотентна (TelegramSendGuard): ретрай после потерянного ответа
 * Telegram больше не даёт вторую одинаковую копию в чате — подавляется guard'ом.
 */
class SendZapisiBotMessageJob implements ShouldQueue
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
        $token = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
        if ($token === '' || $this->chatId === '') {
            return;
        }

        if (! TelegramSendGuard::claim($this->chatId, $this->text)) {
            Log::info('SendZapisiBotMessageJob: identical message already sent, duplicate suppressed', [
                'chat_id' => $this->chatId,
                'dedup_key' => TelegramSendGuard::key($this->chatId, $this->text),
            ]);

            return;
        }

        // Таймауты короче дефолтных: висящий ответ не должен держать воркер,
        // а окно неоднозначности «дошло/не дошло» — минимальным.
        try {
            $response = Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $this->text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $exception) {
            // Транспортный сбой: соединение не состоялось ИЛИ ответ потерян уже
            // после отправки. Guzzle/Laravel сообщают оба случая ОДНИМ классом
            // исключения (ConnectionException), различить их нельзя — значит,
            // доставка под вопросом и ретрай может дать дубль. Ключ НЕ отпускаем:
            // подавленный ретрай дешевле второй копии в чат группы.
            Log::warning('SendZapisiBotMessageJob: transport failure, retry suppressed to avoid duplicate', [
                'chat_id' => $this->chatId,
                'error' => $exception->getMessage(),
                'dedup_key' => TelegramSendGuard::key($this->chatId, $this->text),
            ]);

            return;
        }

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            Log::warning('SendZapisiBotMessageJob: Telegram API error', [
                'chat_id' => $this->chatId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            // Telegram ответил отказом = доставлено не было; ключ отпускаем,
            // чтобы бэкофф-ретрай мог отправить.
            TelegramSendGuard::release($this->chatId, $this->text);
            throw new \RuntimeException('Telegram sendMessage error: '.$response->body());
        }

        Log::info('SendZapisiBotMessageJob: message sent', [
            'chat_id' => $this->chatId,
            'length' => mb_strlen($this->text),
            'dedup_key' => TelegramSendGuard::key($this->chatId, $this->text),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('SendZapisiBotMessageJob failed permanently', [
            'chat_id' => $this->chatId,
            'error' => $exception->getMessage(),
        ]);
    }
}
