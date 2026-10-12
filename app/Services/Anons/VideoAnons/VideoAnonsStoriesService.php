<?php

declare(strict_types=1);

namespace App\Services\Anons\VideoAnons;

use App\Models\StoryPost;
use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * H6547: ответ MG заголовком на черновик видео-анонса → сторис-очередь
 * персоны @rusamskrtam.
 *
 * Черновик (DistributeVideoAnonsJob) вешает pending-ключи
 * video-anons:pending:{adminChat}:{draftMsg} с координатами видео; этот
 * сервис вызывается из главного вебхука на КАЖДОЕ сообщение и разбирает
 * только reply-и админов на такие ключи. Заголовок MG = виза: строка
 * StoryPost создаётся СРАЗУ approved (lane=persona, kind=video), публикацию
 * делает существующий планировщик stories:publish-story (ежечасно, под
 * madeline-локом) — никаких отдельных вызовов MadelineProto отсюда.
 *
 * /ga/-ключ: один вечнозелёный vanons-tg-v1 (config/tracked_links.php),
 * издатель извлекает ссылку из подписи сам (trackedStoryLink). Погружение
 * в пер-видео креативы — только если MG попросит разбивку.
 *
 * Возврат true = апдейт наш (reply на черновик), вебхук отвечает ok и не
 * прогоняет сообщение через студенческие ветки.
 */
class VideoAnonsStoriesService
{
    public function handleAdminReply(array $message): bool
    {
        if (! config('video-anons.stories_enabled')) {
            return false;
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);
        $fromId = (int) ($message['from']['id'] ?? 0);
        $replyTo = (int) ($message['reply_to_message']['message_id'] ?? 0);
        if ($chatId === 0 || $fromId === 0 || $replyTo === 0) {
            return false;
        }

        $adminIds = array_map('intval', app(TelegramAdminNotifier::class)->adminChatIds());
        if (! in_array($fromId, $adminIds, true)) {
            return false;
        }

        try {
            $pending = Redis::get(sprintf('video-anons:pending:%d:%d', $chatId, $replyTo));
        } catch (Throwable $exception) {
            Log::warning('VideoAnons stories: pending lookup failed', ['error' => $exception->getMessage()]);

            return false;
        }

        if (! is_string($pending) || $pending === '') {
            return false;
        }

        $context = json_decode($pending, true);
        if (! is_array($context)) {
            return false;
        }

        $headline = trim((string) ($message['text'] ?? ''));
        if ($headline === '' || mb_strlen($headline) > 200) {
            $this->reply($chatId, '✍️ Пришлите заголовок текстом в reply на черновик (до 200 символов) — он станет подписью сторис.');

            return true;
        }

        $mediaPath = (string) ($context['media_path'] ?? '');
        if ($mediaPath === '' || ! is_file($mediaPath) || ! is_readable($mediaPath)) {
            $this->reply($chatId, '⚠️ Медиа-файл видео не найден в харвест-сторе ('.$mediaPath.') — сторис не собрать. Скачайте файл или дождитесь следующего видео.');

            return true;
        }

        $sourceKey = 'harvest:'.md5($mediaPath);
        $existing = StoryPost::query()
            ->where('source', StoryPost::SOURCE_HARVEST)
            ->where('source_key', $sourceKey)
            ->first();
        if ($existing !== null) {
            $this->reply($chatId, "ℹ️ Это видео уже в сторис-очереди: #{$existing->id} ({$existing->status}).");

            return true;
        }

        $link = rtrim((string) config('app.url'), '/').'/ga/vanons-tg-v1';
        $post = StoryPost::query()->create([
            'kind' => StoryPost::KIND_VIDEO,
            'lane' => StoryPost::LANE_PERSONA,
            'payload' => $headline."\n\n".$link,
            'media_path' => $mediaPath,
            'source' => StoryPost::SOURCE_HARVEST,
            'source_key' => $sourceKey,
            'status' => StoryPost::STATUS_APPROVED,
            'publish_at' => now(),
            'journal' => now()->toDateTimeString().' source: video-anons (H6546/H6547), заголовок MG reply-ом на черновик,'
                .' курс '.$context['course'].' — авто-approve, публикация stories:publish-story.',
        ]);

        try {
            Redis::del(sprintf('video-anons:pending:%d:%d', $chatId, $replyTo));
        } catch (Throwable $exception) {
            Log::warning('VideoAnons stories: pending cleanup failed', ['error' => $exception->getMessage()]);
        }

        Log::info('VideoAnons stories: persona story queued from MG headline', [
            'story_post_id' => $post->id,
            'course' => $context['course'] ?? null,
            'media_path' => $mediaPath,
        ]);

        $this->reply($chatId, "✅ Сторис поставлена в очередь персоны: #{$post->id} (approved, kind=video).\n"
            .'Заголовок: «'.$headline.'»'."\n"
            .'Публикация — планировщиком stories:publish-story (тик ежечасно, ссылка '.$link.').');

        return true;
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
