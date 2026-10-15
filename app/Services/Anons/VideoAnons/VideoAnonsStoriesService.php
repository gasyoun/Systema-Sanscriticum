<?php

declare(strict_types=1);

namespace App\Services\Anons\VideoAnons;

use App\Jobs\PublishVideoAnonsVkWallJob;
use App\Jobs\UploadVideoAnonsShortJob;
use App\Models\StoryPost;
use App\Services\Access\TelegramAdminNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * H6547: ответ MG заголовком на черновик видео-анонса → сторис-очередь
 * персоны @rusamskrtam.
 *
 * Черновик (DistributeVideoAnonsJob) вешает pending-ключи
 * video-anons:pending:{adminChat}:{draftMsg} с координатами видео; этот
 * сервис вызывается из главного вебхука И из бизнес-вебхука основного бота
 * (живой промах 13-10: черновик уходит от @samskrte_ops_bot, чей вебхук =
 * /api/webhooks/telegram-business). Заголовок принимается ДВУМЯ формами:
 *  - reply на сообщение черновика (точный pending-ключ);
 *  - просто текст от админа в личку бота — без reply: pending резолвится
 *    сканом по автору, принимаются ключи младше 2 ч (старее — куратор
 *    протянул, авто-съедание чужого текста опаснее удобства).
 *
 * Заголовок MG = виза. H6547: строка StoryPost создаётся СРАЗУ approved
 * (lane=persona, kind=video), публикацию делает существующий планировщик
 * stories:publish-story (ежечасно, под madeline-локом) — никаких отдельных
 * вызовов MadelineProto отсюда. H6574: той же визой диспатчатся публичные
 * поверхности — YouTube Shorts (UploadVideoAnonsShortJob) и VK-стена
 * (PublishVideoAnonsVkWallJob) — каждый за своим флагом config/video-anons.php,
 * VK вдобавок за механическим гейтом авторизованных групп (fail-closed).
 * Нет визы (заголовка MG) — не уходит ничего.
 *
 * /ga/-ключи: vanons-tg-v1 (сторис), vanons-yt-v1 (Shorts), vanons-vk-v1
 * (VK) — config/tracked_links.php, один вечнозелёный ключ на сёрфейс.
 *
 * Возврат true = апдейт наш, вебхук отвечает ok и не прогоняет сообщение
 * через студенческие ветки.
 */
class VideoAnonsStoriesService
{
    /** Окно авто-приёма «голого» заголовка без reply. */
    private const BARE_HEADLINE_MAX_AGE_MINUTES = 120;

    public function handleAdminReply(array $message): bool
    {
        $storiesEnabled = (bool) config('video-anons.stories_enabled');
        $ytEnabled = (bool) config('video-anons.yt_shorts_enabled');
        $vkEnabled = (bool) config('video-anons.vk_wall_enabled');
        if (! $storiesEnabled && ! $ytEnabled && ! $vkEnabled) {
            return false;
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);
        $fromId = (int) ($message['from']['id'] ?? 0);
        if ($chatId === 0 || $fromId === 0) {
            return false;
        }

        $adminIds = array_map('intval', app(TelegramAdminNotifier::class)->adminChatIds());
        if (! in_array($fromId, $adminIds, true)) {
            return false;
        }

        $replyTo = (int) ($message['reply_to_message']['message_id'] ?? 0);
        $pending = null;
        $pendingKey = null;
        if ($replyTo !== 0) {
            $pendingKey = sprintf('video-anons:pending:%d:%d', $chatId, $replyTo);
            $pending = $this->readPending($pendingKey);
        }

        if ($pending === null) {
            // Живой случай 13-10: заголовок прислан обычным сообщением без
            // reply — резолвим pending сканом по автору, младше 2 ч.
            $resolved = $this->resolvePendingByAuthor($fromId);
            if ($resolved === null) {
                return false;
            }

            $pendingKey = $resolved['key'];
            $pending = $resolved['context'];
        }

        if ($pending === null) {
            return false;
        }

        $headline = trim((string) ($message['text'] ?? ''));
        if ($headline === '' || mb_strlen($headline) > 200) {
            $this->reply($chatId, '✍️ Пришлите заголовок текстом (до 200 символов) — он станет подписью сторис.');

            return true;
        }

        $mediaPath = (string) ($pending['media_path'] ?? '');
        if ($mediaPath === '' || ! is_file($mediaPath) || ! is_readable($mediaPath)) {
            $this->reply($chatId, '⚠️ Медиа-файл видео не найден в харвест-сторе ('.$mediaPath.') — сторис не собрать. Скачайте файл или дождитесь следующего видео.');

            return true;
        }

        if ($storiesEnabled) {
            $sourceKey = 'harvest:'.md5($mediaPath);
            $existing = StoryPost::query()
                ->where('source', StoryPost::SOURCE_HARVEST)
                ->where('source_key', $sourceKey)
                ->first();
            if ($existing !== null) {
                $this->reply($chatId, "ℹ️ Это видео уже в сторис-очереди: #{$existing->id} ({$existing->status}).");

                return true;
            }
        }

        $sourceChatId = (int) ($pending['source_chat_id'] ?? 0);
        $sourceMessageId = (int) ($pending['source_message_id'] ?? 0);
        $courseKey = (string) ($pending['course'] ?? '');

        $replyLines = [];
        if ($storiesEnabled) {
            $link = rtrim((string) config('app.url'), '/').'/ga/vanons-tg-v1';
            $post = StoryPost::query()->create([
                'kind' => StoryPost::KIND_VIDEO,
                'lane' => StoryPost::LANE_PERSONA,
                'payload' => $headline."\n\n".$link,
                'media_path' => $mediaPath,
                'source' => StoryPost::SOURCE_HARVEST,
                'source_key' => 'harvest:'.md5($mediaPath),
                'status' => StoryPost::STATUS_APPROVED,
                'publish_at' => now(),
                'journal' => now()->toDateTimeString().' source: video-anons (H6546/H6547), заголовок MG,'
                    .' курс '.$courseKey.' — авто-approve, публикация stories:publish-story.',
            ]);

            Log::info('VideoAnons stories: persona story queued from MG headline', [
                'story_post_id' => $post->id,
                'course' => $courseKey,
                'media_path' => $mediaPath,
                'resolved_by' => $replyTo !== 0 ? 'reply' : 'bare-headline-scan',
            ]);
            $replyLines[] = "✅ Сторис поставлена в очередь персоны: #{$post->id} (approved, kind=video).\n"
                .'Заголовок: «'.$headline.'»'."\n"
                .'Публикация — планировщиком stories:publish-story (тик ежечасно, ссылка '.$link.').';
        } else {
            $replyLines[] = '✅ Виза заголовка принята (сторис выключены).';
        }

        if ($ytEnabled) {
            UploadVideoAnonsShortJob::dispatch($sourceChatId, $sourceMessageId, $courseKey, $mediaPath, $headline);
            Log::info('VideoAnons shorts: upload job dispatched', [
                'source_chat_id' => $sourceChatId,
                'source_message_id' => $sourceMessageId,
                'course' => $courseKey,
            ]);
            $replyLines[] = '▶️ Shorts: джоб загрузки в очереди YouTube (канал записей, описание с vanons-yt-v1).';
        }

        if ($vkEnabled) {
            PublishVideoAnonsVkWallJob::dispatch($sourceChatId, $sourceMessageId, $courseKey, $mediaPath, $headline);
            Log::info('VideoAnons vk: wall post job dispatched', [
                'source_chat_id' => $sourceChatId,
                'source_message_id' => $sourceMessageId,
                'course' => $courseKey,
            ]);
            $replyLines[] = '🅥 VK: джоб поста в очереди (гейт авторизованных групп, vanons-vk-v1).';
        }

        $this->releasePending($pendingKey);

        $this->reply($chatId, implode("\n", $replyLines));

        return true;
    }

    /**
     * @return null|array<string, mixed>
     */
    private function readPending(string $key): ?array
    {
        try {
            $pending = Redis::get($key);
        } catch (Throwable $exception) {
            Log::warning('VideoAnons stories: pending lookup failed', ['error' => $exception->getMessage()]);

            return null;
        }

        if (! is_string($pending) || $pending === '') {
            return null;
        }

        return is_array(json_decode($pending, true)) ? json_decode($pending, true) : null;
    }

    /**
     * Резолв pending по автору без reply: ключи video-anons:pending:{id}:*
     * младше BARE_HEADLINE_MAX_AGE_MINUTES (created_at в контексте; у ключей
     * до H6547-fix2 его нет — грандфазерим как свежие, окно приёмки и так
     * живое только у актуального видео).
     *
     * @return null|array{key: string, context: array<string, mixed>}
     */
    private function resolvePendingByAuthor(int $fromId): ?array
    {
        try {
            $keys = Redis::keys(sprintf('video-anons:pending:%d:*', $fromId));
        } catch (Throwable $exception) {
            Log::warning('VideoAnons stories: pending scan failed', ['error' => $exception->getMessage()]);

            return null;
        }

        if (! is_array($keys) || $keys === []) {
            return null;
        }

        sort($keys);
        $now = now();
        foreach ($keys as $full) {
            $raw = is_string($full) ? preg_replace('/^laravel_database_/', '', $full) : null;
            if (! is_string($raw) || $raw === '') {
                continue;
            }

            $context = $this->readPending($raw);
            if ($context === null) {
                continue;
            }

            $createdAt = $context['created_at'] ?? null;
            if (is_string($createdAt) && $createdAt !== '') {
                try {
                    if (abs($now->diffInMinutes(CarbonImmutable::parse($createdAt))) > self::BARE_HEADLINE_MAX_AGE_MINUTES) {
                        continue;
                    }
                } catch (Throwable) {
                    // неразбираемая метка — грандфазер (см. докблок)
                }
            }

            return ['key' => $raw, 'context' => $context];
        }

        return null;
    }

    private function releasePending(string $key): void
    {
        try {
            Redis::del($key);
        } catch (Throwable $exception) {
            Log::warning('VideoAnons stories: pending cleanup failed', ['error' => $exception->getMessage()]);
        }
    }

    /** Ответ в личку админа; сбой отправки не ломает обработку (строка уже создана). */
    private function reply(int $chatId, string $text): void
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            return;
        }

        app(TelegramAdminNotifier::class)->send($token, (string) $chatId, $text);
    }
}
