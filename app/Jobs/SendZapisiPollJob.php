<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MarketingSetting;
use App\Models\TelegramPoll;
use App\Support\TelegramSendGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправляет опрос @zapisi_ORSbot в чат группы (sendPoll, is_anonymous=false —
 * результаты нужны поимённо). Контракт TelegramSendGuard, как у
 * SendZapisiBotMessageJob: клейм до отправки; Telegram ответил отказом —
 * отпускаем и ретраим; ответ потерялся — ключ держим (второй одинаковый опрос
 * в чате хуже потерянного), статус «не ясно, дошёл ли».
 */
class SendZapisiPollJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $pollId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [15, 60, 300];
    }

    public function handle(): void
    {
        $poll = TelegramPoll::find($this->pollId);
        if ($poll === null || ! in_array($poll->status, [TelegramPoll::STATUS_PENDING, TelegramPoll::STATUS_FAILED], true)) {
            return;
        }

        $token = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
        if ($token === '' || $poll->chat_id === '') {
            $poll->update(['status' => TelegramPoll::STATUS_FAILED, 'error' => 'Не задан токен бота или чат группы.']);

            return;
        }

        $key = 'tg:poll:'.$poll->id;
        if (! TelegramSendGuard::claimKey($key, 86400)) {
            Log::info('SendZapisiPollJob: poll already being sent, duplicate suppressed', ['poll_id' => $poll->id]);

            return;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/sendPoll", [
                'chat_id' => $poll->chat_id,
                'question' => $poll->question,
                'options' => array_map(fn (string $text): array => ['text' => $text], array_values($poll->options)),
                'is_anonymous' => false,
                'allows_multiple_answers' => $poll->allows_multiple,
            ]);
        } catch (\Throwable $exception) {
            $poll->update(['status' => TelegramPoll::STATUS_UNKNOWN, 'error' => $exception->getMessage()]);
            Log::warning('SendZapisiPollJob: transport failure, retry suppressed to avoid duplicate poll', [
                'poll_id' => $poll->id,
                'chat_id' => $poll->chat_id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            TelegramSendGuard::releaseKey($key);
            $poll->update([
                'status' => TelegramPoll::STATUS_FAILED,
                'error' => (string) ($response->json('description') ?? $response->body()),
            ]);

            throw new \RuntimeException('Telegram sendPoll error: '.$response->body());
        }

        $poll->update([
            'status' => TelegramPoll::STATUS_SENT,
            'tg_poll_id' => (string) $response->json('result.poll.id'),
            'message_id' => (int) $response->json('result.message_id'),
            'sent_at' => now(),
            'error' => null,
        ]);

        Log::info('SendZapisiPollJob: poll sent', [
            'poll_id' => $poll->id,
            'chat_id' => $poll->chat_id,
            'tg_poll_id' => $poll->tg_poll_id,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('SendZapisiPollJob failed permanently', [
            'poll_id' => $this->pollId,
            'error' => $exception->getMessage(),
        ]);
    }
}
