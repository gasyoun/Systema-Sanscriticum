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
 * H6574: видео-анонс → VK-стена. Конвенция vk-wall эстейта (vk_mirror.py /
 * vk_poll_crosspost.py, H6153/H5079): community-token wall.post, честные
 * деградации, никаких тихих смертей — и МЕХАНИЧЕСКИЙ ГЕЙТ авторизованных
 * групп: пустой VK_PUBLISH_AUTHORIZED_GROUPS = публикация отказывается,
 * dry/тесты живут на отдельной тестовой группе, не на проде.
 *
 * Виза MG = сам заголовок (джоб диспатчится только из обработчика заголовка
 * VideoAnonsStoriesService); без заголовка джоб отказывает (fail-closed):
 * явное слово MG на каждый пост — не передаётся ничем, кроме его текста.
 *
 * Вложение: video.save → multipart-загрузка файла → attachments=video{oid}_{id};
 * любой отказ вложения = ДЕГРАДАЦИЯ в текст-пост (заголовок + /ga/vanons-vk-v1),
 * названная в логе и в уведомлении админам — зеркало всё равно доставляется
 * (паттерн conveyor, TG-публикация от VK не зависит по H6153 — здесь то же:
 * сторис-сёрфейс уже опубликован независимо).
 */
class PublishVideoAnonsVkWallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** Клейм на время полёта одного прогона. */
    private const IN_FLIGHT_TTL = 300;

    /** Окно подавления дублей после успешной публикации. */
    private const DONE_TTL = 604800;

    private const VK_API = 'https://api.vk.com/method';

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
        if (! config('video-anons.vk_wall_enabled')) {
            return;
        }

        $claimKey = $this->claimKeyFor();
        if (! TelegramSendGuard::claimKey($claimKey, self::IN_FLIGHT_TTL)) {
            Log::info('VideoAnons vk: already posted or in flight, duplicate suppressed', [
                'source_chat_id' => $this->sourceChatId,
                'source_message_id' => $this->sourceMessageId,
            ]);

            return;
        }

        $headline = trim($this->headline);
        if ($headline === '') {
            // Слова MG нет — поста нет (fail-closed; в норме недостижимо:
            // диспатч идёт только из обработчика заголовка).
            TelegramSendGuard::extendKey($claimKey, self::DONE_TTL);
            Log::warning('VideoAnons vk: no MG headline — publish refused (fail-closed)', [
                'source_chat_id' => $this->sourceChatId,
                'source_message_id' => $this->sourceMessageId,
            ]);

            return;
        }

        $authorized = array_map('intval', (array) config('video-anons.vk.authorized_group_ids', []));
        $targetGroupId = (int) config('video-anons.vk.target_group_id', 0);

        if ($authorized === []) {
            $this->refusePermanently($claimKey, 'VK_PUBLISH_AUTHORIZED_GROUPS пуст — гейт авторизации запрещает публикацию (fail-closed)');

            return;
        }

        if ($targetGroupId === 0 || ! in_array($targetGroupId, $authorized, true)) {
            $this->refusePermanently($claimKey, "целевая группа {$targetGroupId} не входит в авторизованный список (".implode(',', $authorized).')');

            return;
        }

        $token = (string) config('video-anons.vk.access_token');
        if ($token === '') {
            $this->refusePermanently($claimKey, 'токен VK_WALL_ACCESS_TOKEN не настроен');

            return;
        }

        $message = $headline."\n\n".rtrim((string) config('app.url'), '/').'/ga/vanons-vk-v1';

        $attachments = $this->uploadVideoAttachment($token, $targetGroupId, $headline);

        $postId = $this->wallPost($token, $targetGroupId, $message, $attachments);
        if ($postId === null) {
            return; // явный отказ или обрыв — уже залогированы, ретрай решён
        }

        TelegramSendGuard::extendKey($claimKey, self::DONE_TTL);

        $permalink = "https://vk.ru/wall-{$targetGroupId}_{$postId}";
        Log::info('VideoAnons vk: wall post published', [
            'permalink' => $permalink,
            'attachments' => $attachments,
            'course' => $this->courseKey,
            'source_chat_id' => $this->sourceChatId,
            'source_message_id' => $this->sourceMessageId,
        ]);
        $this->notifyAdmins('✅ VK: пост опубликован '.$permalink."\n"
            .'Заголовок: «'.$headline.'»'.($attachments === [] ? "\n(без видео: вложение не загрузилось — деградация в текст-пост)" : ''));
    }

    /**
     * video.save → multipart-загрузка файла. Любой отказ = [] (текст-пост,
     * деградация названа) — публикация не отменяется.
     *
     * @return list<string>
     */
    private function uploadVideoAttachment(string $token, int $groupId, string $headline): array
    {
        $localPath = (string) $this->mediaPath;
        if ($localPath === '' || ! is_file($localPath) || ! is_readable($localPath)) {
            Log::warning('VideoAnons vk: video file missing — degrading to text post', [
                'media_path' => $localPath,
            ]);

            return [];
        }

        try {
            $save = Http::asForm()->post(self::VK_API.'/video.save', [
                'access_token' => $token,
                'v' => (string) config('video-anons.vk.api_version', '5.199'),
                'group_id' => $groupId,
                'name' => mb_substr($headline, 0, 255),
                'description' => mb_substr($headline."\n\n".rtrim((string) config('app.url'), '/').'/ga/vanons-vk-v1', 0, 9000),
            ]);

            $uploadUrl = (string) $save->json('response.upload_url');
            $ownerId = (int) $save->json('response.owner_id');
            $videoId = (int) $save->json('response.video_id');
            if (! $save->successful() || $uploadUrl === '' || $ownerId === 0 || $videoId === 0) {
                Log::warning('VideoAnons vk: video.save declined — degrading to text post', [
                    'status' => $save->status(),
                    'error' => (string) $save->json('error.error_msg'),
                ]);

                return [];
            }

            $upload = Http::timeout(max(30, $this->timeout))
                ->attach('video_file', (string) file_get_contents($localPath), basename($localPath))
                ->post($uploadUrl);

            if (! $upload->successful()) {
                Log::warning('VideoAnons vk: video upload failed — degrading to text post', [
                    'status' => $upload->status(),
                ]);

                return [];
            }

            return ['video'.$ownerId.'_'.$videoId];
        } catch (Throwable $exception) {
            Log::warning('VideoAnons vk: video attachment transport failure — degrading to text post', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * wall.post. Возврат: post_id = успех, null = явный отказ VK (уведомление
     * ушло, окно продлено — ретрай причины не лечит) или транспортный обрыв
     * (клейм держит окно, ретрай подавлен — анти-дубль).
     */
    private function wallPost(string $token, int $groupId, string $message, array $attachments): ?int
    {
        $params = [
            'access_token' => $token,
            'v' => (string) config('video-anons.vk.api_version', '5.199'),
            'owner_id' => -$groupId,
            'from_group' => 1,
            'message' => $message,
        ];
        if ($attachments !== []) {
            $params['attachments'] = implode(',', $attachments);
        }

        try {
            $response = Http::connectTimeout(5)->timeout(30)
                ->post(self::VK_API.'/wall.post', $params);
        } catch (Throwable $exception) {
            Log::warning('VideoAnons vk: transport failure, retry suppressed to avoid duplicate', [
                'error' => $exception->getMessage(),
                'source_chat_id' => $this->sourceChatId,
                'source_message_id' => $this->sourceMessageId,
            ]);
            TelegramSendGuard::extendKey($this->claimKeyFor(), self::DONE_TTL);
            $this->notifyAdmins('⚠️ VK: транспортный обрыв при wall.post, ретрай подавлен (анти-дубль). Проверьте стену группы.');

            return null;
        }

        $postId = (int) $response->json('response.post_id');
        if (! $response->successful() || $postId === 0) {
            $code = (int) $response->json('error.error_code');
            $this->notifyAdmins('⚠️ VK: wall.post отклонён (HTTP '.$response->status().', err '.$code.' '.(string) $response->json('error.error_msg').') — пост НЕ опубликован.');
            Log::warning('VideoAnons vk: wall.post declined', [
                'status' => $response->status(),
                'error_code' => $code,
                'error_msg' => (string) $response->json('error.error_msg'),
                'source_chat_id' => $this->sourceChatId,
                'source_message_id' => $this->sourceMessageId,
            ]);
            TelegramSendGuard::extendKey($this->claimKeyFor(), self::DONE_TTL);

            return null;
        }

        return $postId;
    }

    private function refusePermanently(string $claimKey, string $reason): void
    {
        TelegramSendGuard::extendKey($claimKey, self::DONE_TTL);
        Log::warning('VideoAnons vk: refused permanently', [
            'reason' => $reason,
            'source_chat_id' => $this->sourceChatId,
            'source_message_id' => $this->sourceMessageId,
        ]);
        $this->notifyAdmins('⚠️ VK: пост НЕ опубликован — '.$reason);
    }

    private function claimKeyFor(): string
    {
        return sprintf('video-anons:vk:%d:%d', $this->sourceChatId, $this->sourceMessageId);
    }

    private function notifyAdmins(string $text): void
    {
        try {
            app(TelegramAdminNotifier::class)->notifyAdmins($text);
        } catch (Throwable $exception) {
            Log::warning('VideoAnons vk: admin notice failed', ['error' => $exception->getMessage()]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('PublishVideoAnonsVkWallJob failed permanently', [
            'course' => $this->courseKey,
            'source_chat_id' => $this->sourceChatId,
            'source_message_id' => $this->sourceMessageId,
            'error' => $exception->getMessage(),
        ]);
        $this->notifyAdmins('⚠️ VK: публикация не удалась после ретраев — '.$exception->getMessage());
    }
}
