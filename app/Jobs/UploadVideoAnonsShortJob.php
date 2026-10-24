<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Access\TelegramAdminNotifier;
use App\Support\TelegramSendGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * H6574: видео-анонс → YouTube Shorts (канал записей) через YouTube Data API.
 *
 * Виза — заголовок MG (VideoAnonsStoriesService диспатчит джоб только после
 * него); флаг video-anons.yt_shorts_enabled. Без кредов (GOOGLE_YT_*) джоб
 * отказывает ЯВНО (лог + уведомление админам) и ничего не постит — fail-closed.
 *
 * Загрузка — resumable upload (uploadType=resumable, один PUT всего файла:
 * видео из учебного чата на порядки меньше потолка запроса). Shorts
 * определяется самим YouTube по форме/длительности ролика — отдельного
 * параметра API нет; заголовок ≤100 символов, в описании вечнозелёный
 * /ga/vanons-yt-v1 (config/tracked_links.php).
 *
 * Идемпотентность — клейм video-anons:yt:{chat}:{msg} («на время полёта»,
 * продление до недельного окна после успеха — контракт TelegramSendGuard):
 * ретрай очереди не задаублирует ролик. Транспортный обрыв ПОСЛЕ отправки
 * байтов (неясно, принял ли YouTube) клейм НЕ отпускает и ретрай подавляет —
 * молча залить второй ролик дешевле честного «проверьте канал» админу.
 * Отказ API ЯВНО до/во время сессии (4xx, квота) — ретрай тоже подавляем:
 * повтор не лечит ни квоту, ни невалидные креды.
 */
class UploadVideoAnonsShortJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** YouTube может принимать видео заметно дольше обычного HTTP-бюджета. */
    public int $timeout = 900;

    /** Клейм на время полёта одного прогона (загрузка бывает долгой). */
    private const IN_FLIGHT_TTL = 900;

    /** Окно подавления дублей после успешной публикации. */
    private const DONE_TTL = 604800;

    private const API = 'https://www.googleapis.com';

    private const OAUTH = 'https://oauth2.googleapis.com/token';

    public function __construct(
        public readonly int $sourceChatId,
        public readonly int $sourceMessageId,
        public readonly string $courseKey,
        public readonly ?string $mediaPath,
        public readonly string $headline,
    ) {
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-long');
        }
        $this->onQueue('messages');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        if (! config('video-anons.yt_shorts_enabled')) {
            return;
        }

        $claimKey = $this->claimKeyFor();
        if (! TelegramSendGuard::claimKey($claimKey, self::IN_FLIGHT_TTL)) {
            Log::info('VideoAnons shorts: already uploaded or in flight, duplicate suppressed', [
                'source_chat_id' => $this->sourceChatId,
                'source_message_id' => $this->sourceMessageId,
            ]);

            return;
        }

        if ((string) config('video-anons.yt.client_id') === ''
            || (string) config('video-anons.yt.client_secret') === ''
            || (string) config('video-anons.yt.refresh_token') === '') {
            $this->refusePermanently($claimKey, 'креды YouTube не настроены (GOOGLE_YT_CLIENT_ID / _CLIENT_SECRET / _REFRESH_TOKEN пусты)');

            return;
        }

        $localPath = (string) $this->mediaPath;
        if ($localPath === '' || ! is_file($localPath) || ! is_readable($localPath)) {
            $this->refusePermanently($claimKey, "медиа-файл не найден в харвест-сторе ({$localPath})");

            return;
        }

        $accessToken = $this->refreshAccessToken();
        if ($accessToken === null) {
            return; // постоянный отказ уже залогирован и доведён админам
        }

        $videoId = $this->resumableUpload($accessToken, $localPath);
        if ($videoId === null || $videoId === '') {
            return; // null = явный отказ (доведён), '' = обрыв (ретрай подавлен, клейм держит окно)
        }

        TelegramSendGuard::extendKey($claimKey, self::DONE_TTL);

        $url = 'https://youtube.com/shorts/'.$videoId;
        Log::info('VideoAnons shorts: uploaded', [
            'video_id' => $videoId,
            'course' => $this->courseKey,
            'source_chat_id' => $this->sourceChatId,
            'source_message_id' => $this->sourceMessageId,
        ]);
        $this->notifyAdmins('✅ Shorts опубликован на канале записей: '.$url."\n"
            .'Заголовок: «'.$this->headline.'»');
    }

    /**
     * Постоянный отказ (нет кредов/файла): окно клейма продлеваем (ретрай
     * очереди бессмыслен), админам уходит явное уведомление, ничего не постится.
     */
    private function refusePermanently(string $claimKey, string $reason): void
    {
        TelegramSendGuard::extendKey($claimKey, self::DONE_TTL);
        Log::warning('VideoAnons shorts: refused permanently', [
            'reason' => $reason,
            'source_chat_id' => $this->sourceChatId,
            'source_message_id' => $this->sourceMessageId,
        ]);
        $this->notifyAdmins('⚠️ Shorts НЕ опубликован: '.$reason);
    }

    /**
     * Refresh token → access token. 5xx = транзиент (исключение — бэкофф-ретрай
     * очереди), 4xx/невалидный ответ = постоянный отказ: уведомление + null.
     */
    private function refreshAccessToken(): ?string
    {
        $response = Http::asForm()->post(self::OAUTH, [
            'client_id' => (string) config('video-anons.yt.client_id'),
            'client_secret' => (string) config('video-anons.yt.client_secret'),
            'refresh_token' => (string) config('video-anons.yt.refresh_token'),
            'grant_type' => 'refresh_token',
        ]);

        if ($response->status() >= 500) {
            throw new RuntimeException('VideoAnons shorts: Google OAuth transient failure, HTTP '.$response->status());
        }

        $accessToken = (string) $response->json('access_token');
        if (! $response->successful() || $accessToken === '') {
            $this->notifyAdmins('⚠️ Shorts НЕ опубликован: Google OAuth отказал (HTTP '.$response->status().', '.(string) $response->json('error').') — проверьте GOOGLE_YT_* креды.');
            Log::warning('VideoAnons shorts: oauth refresh refused', [
                'status' => $response->status(),
                'source_chat_id' => $this->sourceChatId,
                'source_message_id' => $this->sourceMessageId,
            ]);
            TelegramSendGuard::extendKey($this->claimKeyFor(), self::DONE_TTL);

            return null;
        }

        return $accessToken;
    }

    /**
     * Resumable-сессия + один PUT всего файла. Возврат: video id = успех,
     * null = явный отказ API (уведомление ушло, окно продлено), ''
     * = транспортный обрыв с неизвестным исходом (клейм держит окно).
     */
    private function resumableUpload(string $accessToken, string $localPath): ?string
    {
        $size = (int) filesize($localPath);
        $mime = (string) ((function_exists('mime_content_type') ? mime_content_type($localPath) : '') ?: 'video/mp4');

        $metadata = [
            'snippet' => [
                'title' => mb_substr($this->headline, 0, 100),
                'description' => mb_substr($this->headline."\n\n"
                    .rtrim((string) config('app.url'), '/').'/ga/vanons-yt-v1', 0, 5000),
                'categoryId' => (string) config('video-anons.yt.category_id', '27'),
            ],
            'status' => [
                'privacyStatus' => (string) config('video-anons.yt.privacy_status', 'public'),
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        try {
            $session = Http::withToken($accessToken)
                ->withHeaders([
                    'X-Upload-Content-Length' => (string) $size,
                    'X-Upload-Content-Type' => $mime,
                ])
                ->post(self::API.'/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', $metadata);

            if ($session->status() >= 500) {
                throw new RuntimeException('VideoAnons shorts: resumable session transient failure, HTTP '.$session->status());
            }

            $location = (string) $session->header('Location');
            if (! $session->successful() || $location === '') {
                $this->notifyAdmins('⚠️ Shorts НЕ опубликован: YouTube отказал в сессии загрузки (HTTP '.$session->status().', '.mb_substr((string) $session->body(), 0, 300).').');
                Log::warning('VideoAnons shorts: resumable session refused', [
                    'status' => $session->status(),
                    'source_chat_id' => $this->sourceChatId,
                    'source_message_id' => $this->sourceMessageId,
                ]);
                TelegramSendGuard::extendKey($this->claimKeyFor(), self::DONE_TTL);

                return null;
            }

            $uploaded = Http::withToken($accessToken)
                ->withHeaders(['Content-Range' => 'bytes 0-'.max(0, $size - 1).'/'.$size])
                ->timeout(max(30, $this->timeout))
                ->withBody((string) file_get_contents($localPath), $mime)
                ->put($location);

            if ($uploaded->status() >= 500) {
                throw new RuntimeException('VideoAnons shorts: upload transient failure, HTTP '.$uploaded->status());
            }

            $videoId = (string) $uploaded->json('id');
            if (! $uploaded->successful() || $videoId === '') {
                // Отказ получен ЯВНО — загрузка не случилась, повтор не лечит
                // причину (квота/длина/формат): окно продлеваем, без ретрая.
                $this->notifyAdmins('⚠️ Shorts НЕ опубликован: загрузка отклонена (HTTP '.$uploaded->status().', '.mb_substr((string) $uploaded->body(), 0, 300).').');
                Log::warning('VideoAnons shorts: upload declined', [
                    'status' => $uploaded->status(),
                    'source_chat_id' => $this->sourceChatId,
                    'source_message_id' => $this->sourceMessageId,
                ]);
                TelegramSendGuard::extendKey($this->claimKeyFor(), self::DONE_TTL);

                return null;
            }
        } catch (RuntimeException $exception) {
            throw $exception; // транзиент — бэкофф-ретрай очереди
        } catch (Throwable $exception) {
            // Транспортный обрыв (не соединились / ответ потерян): принял ли
            // YouTube ролик — неизвестно. Клейм НЕ отпускаем, ретрай подавляем.
            Log::warning('VideoAnons shorts: transport failure, retry suppressed to avoid duplicate', [
                'error' => $exception->getMessage(),
                'source_chat_id' => $this->sourceChatId,
                'source_message_id' => $this->sourceMessageId,
            ]);
            TelegramSendGuard::extendKey($this->claimKeyFor(), self::DONE_TTL);
            $this->notifyAdmins('⚠️ Shorts: транспортный обрыв при загрузке, ретрай подавлен (анти-дубль). Проверьте канал записей — при необходимости удалите ролик руками.');

            return '';
        }

        return $videoId;
    }

    private function claimKeyFor(): string
    {
        return sprintf('video-anons:yt:%d:%d', $this->sourceChatId, $this->sourceMessageId);
    }

    private function notifyAdmins(string $text): void
    {
        try {
            app(TelegramAdminNotifier::class)->notifyAdmins($text);
        } catch (Throwable $exception) {
            Log::warning('VideoAnons shorts: admin notice failed', ['error' => $exception->getMessage()]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('UploadVideoAnonsShortJob failed permanently', [
            'course' => $this->courseKey,
            'source_chat_id' => $this->sourceChatId,
            'source_message_id' => $this->sourceMessageId,
            'error' => $exception->getMessage(),
        ]);
        $this->notifyAdmins('⚠️ Shorts: публикация не удалась после ретраев — '.$exception->getMessage());
    }
}
