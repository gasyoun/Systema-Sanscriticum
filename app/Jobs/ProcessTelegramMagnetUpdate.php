<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LandingBot;
use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Services\Leads\LeadMagnetDispatcher;
use App\Services\Leads\WaitlistWelcome;
use App\Services\Marathon\MarathonDay1Sender;
use App\Services\Messaging\DeliveryChannelManager;
use App\Support\TelegramChannelEcho;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ProcessTelegramMagnetUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly array $update,
        public readonly ?int $landingBotId = null,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        // Per-bot режим: форвардим ЛЮБОЙ апдейт в n8n (анкета/прогрев), включая
        // callback_query (у него нет ['message'], поэтому делаем это до раннего return).
        if ($this->landingBotId !== null) {
            $bot = LandingBot::find($this->landingBotId);
            if ($bot && ! empty($bot->n8n_forward_url)) {
                ForwardUpdateToN8n::dispatch($bot->n8n_forward_url, $this->update);
            }
        }

        // H3617 — сенсор эха канала: каждый пост в канале приходит боту-админу
        // как channel_post, включая посты НЕ от нас (запланированные в Telegram,
        // ручные). Отпечаток нужен издателю канала для cross-sender дедупа —
        // записываем до раннего return по отсутствию ['message'].
        foreach (['channel_post', 'edited_channel_post'] as $channelKey) {
            if (isset($this->update[$channelKey]) && is_array($this->update[$channelKey])) {
                TelegramChannelEcho::recordFromUpdate($this->update[$channelKey]);
            }
        }

        $message = $this->update['message'] ?? null;
        if (! $message) {
            return;
        }

        $text = $message['text'] ?? '';
        $chatId = $message['chat']['id'] ?? null;

        if (! $chatId) {
            return;
        }

        // H445 Phase 4 (H546) — Day-2 mantra-reading voice note. Checked before
        // the /start-only early return below, since a voice message carries no
        // text. Resolved purely by chat_id (already linked at /start time) —
        // there is no token in a voice message to key off of.
        if (isset($message['voice']['file_id'])) {
            $this->handleMantraVoice($chatId, (string) $message['voice']['file_id']);

            return;
        }

        if (! str_starts_with($text, '/start')) {
            return;
        }

        $parts = explode(' ', $text, 2);
        $token = $parts[1] ?? null;

        if (! $token) {
            // /start без токена — юзер нашёл бота сам, не через форму.
            Log::info('Telegram /start without token', ['chat_id' => $chatId]);

            return;
        }

        $lead = Lead::where('magnet_token', $token)->first();

        if (! $lead) {
            Log::warning('Telegram: unknown magnet_token', ['token' => $token, 'chat_id' => $chatId]);

            return;
        }

        if (! $lead->telegram_chat_id) {
            $lead->update(['telegram_chat_id' => $chatId]);

            // H3339: подписчик статусов (status_block без магнита) первым
            // сообщением получает полный словарь статусов курса.
            WaitlistWelcome::sendIfFreshlyBound($lead->fresh() ?? $lead, 'telegram', (string) $chatId, app(DeliveryChannelManager::class));
        }

        // H1939 residual / product ruling: Day 1 marathon drip immediately after
        // /start (not next calendar day). Idempotent via day1_completed_at; cron
        // marathon:deliver-due still catch-ups if this path was missed.
        $lead = $lead->fresh() ?? $lead;
        $channel = app(DeliveryChannelManager::class)->get('telegram');
        MarathonDay1Sender::trySendIfPending($lead, $channel, ignorePersonalDay: true);

        // Сразу, если у лендинга нет вебинара или окно [старт−offset; старт+грейс]
        // уже открыто; иначе выдаст планировщик magnets:deliver-due.
        LeadMagnetDispatcher::deliverOrDefer($lead, 'telegram');
    }

    /**
     * H445 Phase 4 (H546) — matches an incoming voice note to a `deva`-cohort
     * enrollee still awaiting their Day-2 mantra reading, dispatches the
     * download, and acks the student. Silent no-op for any other voice
     * message (unlinked chat, no pending deva Day-2 task, or already
     * submitted) — a marathon lead-magnet bot getting a random voice note
     * from someone with no reading task open is not an error.
     */
    private function handleMantraVoice(int $chatId, string $fileId): void
    {
        $lead = Lead::where('telegram_chat_id', $chatId)->latest('id')->first();
        if (! $lead) {
            return;
        }

        $enrollment = MarathonEnrollment::query()
            ->where('lead_id', $lead->id)
            ->where('cohort', MarathonEnrollment::COHORT_DEVA)
            ->whereNull('day2_voice_received_at')
            ->latest('id')
            ->first();

        if (! $enrollment) {
            return;
        }

        DownloadMarathonMantraVoice::dispatch($enrollment->id, $fileId);

        $channel = app(DeliveryChannelManager::class)->get('telegram');
        $channel->sendMessage(
            (string) $chatId,
            $enrollment->isPaidTrack()
                ? '🙏 Получили! Куратор прослушает и даст обратную связь.'
                : '🙏 Получили! Сверьтесь с разбором по словам выше — это самопроверка, отдельного ответа не будет.',
        );
    }

    /**
     * Исчерпаны ретраи: логируем, чтобы «магнит не пришёл» не терялся молча.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('ProcessTelegramMagnetUpdate: доставка магнита не удалась', [
            'chat_id' => $this->update['message']['chat']['id'] ?? null,
            'landing_bot_id' => $this->landingBotId,
            'error' => $e->getMessage(),
        ]);
    }
}
