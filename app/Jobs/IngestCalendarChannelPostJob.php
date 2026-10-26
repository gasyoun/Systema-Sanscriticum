<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContentCalendarPublication;
use App\Models\ContentCalendarSlot;
use App\Services\Content\CalendarTelegramPublisher;
use App\Services\Content\TelegramEntityHtml;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Пост, вышедший в TG-канале-источнике («Оповещение санскритян»), → слот
 * календаря (22-10-2026). Публикует его в канале любой админ — сразу или
 * отложкой Telegram; бот видит пост в момент выхода (channel_post).
 *
 * Слот создаётся уже с publication источника = published, поэтому
 * PublishCalendarSlotJob разносит пост только по остальным площадкам: в
 * TG-каналы — copyMessage(s) оригинала (разметка и медиа без пересборки),
 * в VK — плоский текст + первое вложение, скачанное через getFile (Bot API
 * отдаёт файлы до 20 МБ; крупнее — VK получит пост без вложения).
 *
 * Альбом приходит отдельными апдейтами с общим media_group_id: все части
 * сливаются в один слот под Cache::lock, публикация отложена на
 * tg_album_wait_seconds, чтобы дождаться всех частей.
 */
class IngestCalendarChannelPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** Предел getFile в Bot API. */
    private const MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024;

    /** @param  array<string, mixed>  $post  channel_post из апдейта */
    public function __construct(public readonly array $post)
    {
        $this->onQueue('messages');
    }

    public function handle(): void
    {
        if (! config('content_calendar.tg_ingest_enabled')) {
            return;
        }

        $sourceKey = (string) config('content_calendar.tg_ingest_source');
        $sourceChat = (string) (ContentCalendarSlot::enabledDestinations()[$sourceKey]['chat_id'] ?? '');
        if ($sourceChat === '' || ! $this->isFromChat($sourceChat)) {
            return;
        }

        $messageId = (int) ($this->post['message_id'] ?? 0);
        if ($messageId === 0) {
            return;
        }

        // Наш собственный кросспост в этот канал (слот из админки) — не источник.
        $ours = ContentCalendarPublication::query()
            ->where('destination', $sourceKey)
            ->where('external_id', (string) $messageId)
            ->exists();
        if ($ours) {
            return;
        }

        $group = $this->post['media_group_id'] ?? null;
        $ref = $sourceChat.':'.($group !== null ? 'album:'.$group : $messageId);

        Cache::lock('content-calendar-ingest:'.$ref, 30)->block(15, function () use ($ref, $sourceKey, $sourceChat, $messageId, $group): void {
            $slot = ContentCalendarSlot::query()
                ->where('source_kind', ContentCalendarSlot::SOURCE_TG_CHANNEL)
                ->where('source_ref', $ref)
                ->first();

            $slot === null
                ? $this->createSlot($ref, $sourceKey, $sourceChat, $messageId, $group !== null)
                : $this->appendAlbumPart($slot, $messageId);
        });
    }

    private function createSlot(string $ref, string $sourceKey, string $sourceChat, int $messageId, bool $isAlbum): void
    {
        $body = $this->bodyHtml();
        $publishedAt = isset($this->post['date']) ? now()->setTimestamp((int) $this->post['date']) : now();

        $slot = ContentCalendarSlot::create([
            'slot_date' => $publishedAt->copy()->setTimezone('Europe/Moscow')->toDateString(),
            'slot_type' => ContentCalendarSlot::TYPE_TG_CHANNEL,
            'status' => ContentCalendarSlot::STATUS_SCHEDULED,
            'publish_at' => $publishedAt,
            'source_kind' => ContentCalendarSlot::SOURCE_TG_CHANNEL,
            'source_ref' => $ref,
            'title' => $this->title($body),
            'body' => $body,
            'meta' => ['tg_source' => ['chat_id' => $sourceChat, 'message_ids' => [$messageId]]],
        ]);

        ContentCalendarPublication::create([
            'content_calendar_slot_id' => $slot->id,
            'destination' => $sourceKey,
            'status' => ContentCalendarPublication::STATUS_PUBLISHED,
            'attempts' => 0,
            'external_id' => (string) $messageId,
            'permalink' => CalendarTelegramPublisher::permalink($sourceChat, $messageId),
            'published_at' => $publishedAt,
        ]);

        $this->attachMedia($slot, $messageId);

        if (config('features.content_calendar_autopilot')) {
            $wait = $isAlbum ? (int) config('content_calendar.tg_album_wait_seconds', 20) : 0;
            PublishCalendarSlotJob::dispatch($slot->id)->delay(now()->addSeconds($wait));
        }

        Log::info('IngestCalendarChannelPostJob: пост канала → слот календаря', [
            'calendar_slot_id' => $slot->id,
            'source_ref' => $ref,
        ]);
    }

    private function appendAlbumPart(ContentCalendarSlot $slot, int $messageId): void
    {
        $meta = $slot->meta ?? [];
        $ids = (array) ($meta['tg_source']['message_ids'] ?? []);
        if (in_array($messageId, $ids, true)) {
            return;
        }
        $ids[] = $messageId;
        sort($ids);
        $meta['tg_source']['message_ids'] = $ids;

        $updates = ['meta' => $meta];
        // Подпись альбома может прийти на любой его части.
        if (blank($slot->body) && $this->bodyHtml() !== '') {
            $updates['body'] = $this->bodyHtml();
            $updates['title'] = $this->title($updates['body']);
        }
        $slot->forceFill($updates)->save();

        if (blank($slot->media_path)) {
            $this->attachMedia($slot, $messageId);
        }
    }

    private function isFromChat(string $chatId): bool
    {
        $chat = (array) ($this->post['chat'] ?? []);
        if (str_starts_with($chatId, '@')) {
            return strcasecmp(ltrim($chatId, '@'), (string) ($chat['username'] ?? '')) === 0;
        }

        return (string) ($chat['id'] ?? '') === $chatId;
    }

    private function bodyHtml(): string
    {
        $text = (string) ($this->post['text'] ?? $this->post['caption'] ?? '');
        $entities = (array) ($this->post['entities'] ?? $this->post['caption_entities'] ?? []);

        return TelegramEntityHtml::toEditorHtml($text, $entities);
    }

    private function title(string $bodyHtml): string
    {
        $plain = trim(strip_tags(str_replace('<br>', ' ', $bodyHtml)));

        return 'Из канала: '.mb_strimwidth(html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'медиа без подписи', 0, 80, '…');
    }

    /** Первое фото/видео поста → диск public, чтобы VK-публикатор мог его загрузить. */
    private function attachMedia(ContentCalendarSlot $slot, int $messageId): void
    {
        $photo = $this->post['photo'] ?? null;
        $file = is_array($photo) && $photo !== [] ? end($photo) : ($this->post['video'] ?? null);
        if (! is_array($file) || ! isset($file['file_id'])) {
            return;
        }

        $isVideo = ! is_array($photo) || $photo === [];
        if ((int) ($file['file_size'] ?? 0) > self::MAX_DOWNLOAD_BYTES) {
            $this->noteMedia($slot, 'вложение больше 20 МБ — Bot API не отдаёт его, в VK пост уйдёт без вложения');

            return;
        }

        $token = CalendarTelegramPublisher::botToken();
        try {
            $path = (string) Http::timeout(30)->get("https://api.telegram.org/bot{$token}/getFile", ['file_id' => $file['file_id']])
                ->json('result.file_path');
            if ($path === '') {
                throw new \RuntimeException('getFile без file_path');
            }
            $content = Http::timeout(120)->get("https://api.telegram.org/file/bot{$token}/{$path}");
            if (! $content->successful()) {
                throw new \RuntimeException('скачивание файла: HTTP '.$content->status());
            }
        } catch (Throwable $e) {
            $this->noteMedia($slot, 'вложение не скачалось из Telegram: '.$e->getMessage());

            return;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: ($isVideo ? 'mp4' : 'jpg');
        $stored = 'content-calendar/tg-'.ltrim((string) ($this->post['chat']['id'] ?? 'chat'), '-').'-'.$messageId.'.'.$ext;
        Storage::disk(ContentCalendarSlot::MEDIA_DISK)->put($stored, $content->body());
        $slot->forceFill(['media_path' => $stored])->save();
    }

    private function noteMedia(ContentCalendarSlot $slot, string $note): void
    {
        $slot->forceFill(['meta' => array_merge($slot->meta ?? [], ['tg_media_note' => $note])])->save();
        Log::warning('IngestCalendarChannelPostJob: '.$note, ['calendar_slot_id' => $slot->id]);
    }
}
