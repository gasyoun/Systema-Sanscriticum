<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MarketingSetting;
use App\Services\HomeworkTelegramTagService;
use App\Services\Telegram\AnnounceCancelCallbackService;
use App\Services\Telegram\AnnounceCancelService;
use App\Services\Telegram\CancelClassCommandService;
use App\Services\Telegram\CancelUsageHint;
use App\Services\Telegram\DateAwareCancelService;
use App\Services\Telegram\VacationCommandService;
use App\Services\TelegramHarvest\HarvestStoreWriter;
use App\Services\VacationQuorumService;
use App\Support\TelegramSendGuard;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Track C (H164, D8/D11): normalizes a Telegram Bot API update from the
 * zapisi_ORSbot chat into the SAME corpus schema Track B's MadelineProto
 * harvester writes (TelegramHarvestSyncService::normalize), tagged
 * account_type='bot' so it is never conflated with the personal-account peer
 * stream from D7. Feeds the same out-of-git HarvestStoreWriter store.
 *
 * D11 override: media presence dispatches a download job (bot-side media is
 * downloaded, unlike D4's metadata-only default for the rest of Track B).
 */
class ProcessTelegramZapisiUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly array $update)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        // Дедуп по update_id (TelegramSendGuard::claimUpdate): вебхук может быть
        // передиспетчирован, поллер после падения принимает апдейт повторно —
        // без этого клейма повтор шёл бы и в корпус, и форвардом в n8n, и в
        // ответы #ДЗ. Повторная обработка того же апдейта подавляется целиком.
        $updateId = isset($this->update['update_id']) ? (int) $this->update['update_id'] : null;
        if ($updateId !== null && ! TelegramSendGuard::claimUpdate('zapisi', $updateId)) {
            Log::info('ProcessTelegramZapisiUpdate: update already processed, duplicate suppressed', [
                'update_id' => $updateId,
            ]);

            return;
        }

        // Дублируем апдейт в n8n ДО любых фильтров: у бота может быть ровно один
        // вебхук, и пока его держал n8n, наш прод не видел сообщений. Теперь вебхук
        // наш, а n8n получает тот же поток, что раньше слал ему Telegram — иначе
        // его сценарий («ловим названия» → Google Sheets) остался бы без данных.
        // Отсекать здесь ничего нельзя: n8n сам решает, что ему интересно.
        $this->forwardToN8n();

        // #ДЗ / кнопки выбора урока: @zapisi_ORSbot сидит в чатах групп
        // (в отличие от student-bot). Callback и входящий тег обрабатываем здесь.
        $homeworkTag = app(HomeworkTelegramTagService::class);
        if (isset($this->update['callback_query']) && is_array($this->update['callback_query'])) {
            // H4519: кнопки предложения отмены — свои префиксы acx:/acxn.
            if (app(AnnounceCancelCallbackService::class)->handle($this->update['callback_query'])) {
                return;
            }

            $homeworkTag->handleCallback($this->update['callback_query']);

            return;
        }

        $message = $this->update['message'] ?? $this->update['channel_post'] ?? null;
        if (! $message || ! isset($message['chat']['id'], $message['message_id'], $message['date'])) {
            return;
        }

        // H3790 фаза C: reply на опрос кворума каникульной группы = голос платного
        // участника. Голоса по reply_to_message_id, дедуп внутри сервиса.
        if (isset($message['reply_to_message']['message_id'])
            && is_numeric($message['reply_to_message']['message_id'])
            && isset($message['from']['id'])
            && ! empty($message['from']['id'])) {
            try {
                app(VacationQuorumService::class)->registerReply(
                    (string) $message['chat']['id'],
                    (int) $message['reply_to_message']['message_id'],
                    (int) $message['from']['id'],
                );
            } catch (Throwable $e) {
                Log::warning('VacationQuorum: reply registration failed', ['error' => $e->getMessage()]);
            }
        }

        // H4199: reply-команда админа «Отмена занятия» на пост-напоминание.
        // Сервис сам фильтрует (текст / whitelist / маппинг); все отказы — только в лог.
        try {
            app(CancelClassCommandService::class)->handle($message);
        } catch (Throwable $e) {
            Log::warning('CancelClassCommand: handler failed', ['error' => $e->getMessage()]);
        }

        // H4253: датированная отмена («Отмена 23.09 и 30.10») и каникулы/отпуск
        // («Каникулы с 23.09 по 06.10») от преподавателя/менеджера/админа.
        // Оба сервиса сами фильтруют текст и права; отказы — только в лог.
        try {
            app(DateAwareCancelService::class)->handle($message);
        } catch (Throwable $e) {
            Log::warning('DateAwareCancel: handler failed', ['error' => $e->getMessage()]);
        }

        // MG 08-09: преподаватели не знают грамматику команд отмены — если текст
        // начинается с «отмен…», но ни одна команда не адресована, распознанному
        // sender'у (whitelist/ACL) один раз в сутки уходит подсказка формата.
        try {
            CancelUsageHint::maybeSendFor($message);
        } catch (Throwable $e) {
            Log::warning('CancelUsageHint: handler failed', ['error' => $e->getMessage()]);
        }

        try {
            app(VacationCommandService::class)->handle($message);
        } catch (Throwable $e) {
            Log::warning('VacationCommand: handler failed', ['error' => $e->getMessage()]);
        }

        // H4519: анонс отмены обычными словами → предложение-кнопка. Сам фильтрует
        // (флаг / права / группа / кандидаты); никаких отмен без явного тапа «Да».
        try {
            app(AnnounceCancelService::class)->handle($message);
        } catch (Throwable $e) {
            Log::warning('AnnounceCancel: handler failed', ['error' => $e->getMessage()]);
        }

        $chat = $message['chat'];
        $from = $message['from'] ?? null;
        $media = $this->mediaMeta($message);
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');

        if (is_array($chat) && $homeworkTag->isGroupChat($chat) && $homeworkTag->isTagMessage($text)) {
            $homeworkTag->handleIncoming($message);
        }

        if ($text === '' && ! $media['has_media']) {
            return;
        }

        $record = [
            'peer' => isset($chat['username']) ? '@'.$chat['username'] : (string) $chat['id'],
            'telegram_chat_id' => (int) $chat['id'],
            'telegram_message_id' => (int) $message['message_id'],
            'peer_type' => (string) ($chat['type'] ?? 'unknown'),
            'peer_title' => $chat['title'] ?? null,
            'peer_username' => $chat['username'] ?? null,
            'access_level' => 'private_group',
            'account_type' => 'bot',
            'telegram_user_id' => $from['id'] ?? null,
            'author_name' => $from ? trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) : null,
            'author_username' => $from['username'] ?? null,
            'direction' => 'incoming',
            'text' => $text,
            'has_media' => $media['has_media'],
            'media_type' => $media['media_type'],
            'media_caption' => $media['has_media'] && $text !== '' ? $text : null,
            'media_size' => $media['media_size'],
            'media_mime' => $media['media_mime'],
            'sent_at' => CarbonImmutable::createFromTimestamp((int) $message['date'], config('app.timezone'))->toIso8601String(),
            'harvested_at' => now()->toIso8601String(),
            'source_account' => 'zapisi_bot',
        ];

        try {
            $this->storeWriter()->write($record);
        } catch (Throwable $e) {
            Log::error('Telegram zapisi update: raw store write failed', ['error' => $e->getMessage()]);
        }

        // D11: bot-side media is downloaded (override of D4's metadata-only default).
        if ($media['has_media'] && $media['file_id'] !== null) {
            DownloadTelegramZapisiMedia::dispatch(
                (int) $chat['id'],
                (int) $message['message_id'],
                $media['file_id'],
                $media['media_type'],
                substr($record['sent_at'], 0, 10),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{has_media: bool, media_type: ?string, media_size: ?int, media_mime: ?string, file_id: ?string}
     */
    private function mediaMeta(array $message): array
    {
        $empty = ['has_media' => false, 'media_type' => null, 'media_size' => null, 'media_mime' => null, 'file_id' => null];

        if (isset($message['photo']) && is_array($message['photo']) && $message['photo'] !== []) {
            // Telegram sends an array of sizes; the last is the largest.
            $largest = end($message['photo']);

            return ['has_media' => true, 'media_type' => 'photo', 'media_size' => $largest['file_size'] ?? null, 'media_mime' => null, 'file_id' => $largest['file_id'] ?? null];
        }

        foreach (['document' => 'document', 'video' => 'video', 'voice' => 'audio', 'audio' => 'audio', 'video_note' => 'video'] as $key => $type) {
            if (isset($message[$key]) && is_array($message[$key])) {
                $item = $message[$key];

                return [
                    'has_media' => true,
                    'media_type' => $type,
                    'media_size' => $item['file_size'] ?? null,
                    'media_mime' => $item['mime_type'] ?? null,
                    'file_id' => $item['file_id'] ?? null,
                ];
            }
        }

        return $empty;
    }

    /**
     * Форвард в n8n — отдельным джобом (та же очередь `webhooks`), чтобы недоступный
     * n8n не мешал записи сообщения в корпус: у форварда свои ретраи и своя судьба.
     *
     * H4314: роутинг по типу апдейта. my_chat_member (бот добавлен в чат) идёт
     * на zapisi_welcome_n8n_url (приветственная карточка), message/channel_post —
     * на zapisi_n8n_forward_url («ловим названия») как раньше. Пустой адрес = тип
     * не форвардится.
     *
     * H4318 (MG, «железно»): приветственная карточка — НЕ ЧАЩЕ ОДНОГО РАЗА В 24 Ч
     * НА ЧАТ, любыми средствами и при любых обстоятельствах. Клейм в Redis ДО
     * форварда: переживает деплои/рестарты n8n (в отличие от n8n staticData,
     * который import --force обнуляет — проверено 07-09-2026). Redis недоступен =
     * FAIL-CLOSED (карточку не шлём): суточный лимит — требование MG, а не
     * оптимизация; молчание лучше пятёрки дублей.
     */
    private function forwardToN8n(): void
    {
        $url = $this->forwardUrlFor($this->update);

        if ($url === '') {
            return;
        }

        if ($this->isWelcomeUpdate($this->update) && ! $this->claimWelcomeCard($this->update)) {
            return;
        }

        ForwardUpdateToN8n::dispatch($url, $this->update);
    }

    private function isWelcomeUpdate(array $update): bool
    {
        return isset($update['my_chat_member']) && is_array($update['my_chat_member']);
    }

    private function claimWelcomeCard(array $update): bool
    {
        $chatId = $update['my_chat_member']['chat']['id'] ?? null;

        if ($chatId === null) {
            return false;
        }

        $key = 'tg:welcome-card:'.((int) $chatId).':'.now()->format('Ymd');

        try {
            // 24h TTL c привязкой к календарному дню: ровно один клейм на чат в сутки.
            $claimed = (bool) Redis::set($key, now()->toIso8601String(), 'EX', 86400, 'NX');

            if (! $claimed) {
                Log::info('H4318: welcome card rate-limited (1/24h), forward skipped', [
                    'chat_id' => $chatId,
                ]);
            }

            return $claimed;
        } catch (Throwable $exception) {
            // FAIL-CLOSED (H4318): Redis недоступен → карточку не шлём. Суточный
            // лимит — требование MG («железно»), молчание лучше дублей.
            Log::error('H4318: redis unavailable, welcome card FAIL-CLOSED skipped', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function forwardUrlFor(array $update): string
    {
        $settings = MarketingSetting::cached();

        $raw = $this->isWelcomeUpdate($update)
            ? (string) ($settings?->zapisi_welcome_n8n_url ?? '')
            : (string) ($settings?->zapisi_n8n_forward_url ?? '');

        return trim($raw);
    }

    private function storeWriter(): HarvestStoreWriter
    {
        $path = (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));

        return new HarvestStoreWriter($path);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ProcessTelegramZapisiUpdate failed permanently', [
            'chat_id' => $this->update['message']['chat']['id'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
