<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarSlot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Слот календаря → пост в Telegram-канал (Bot API, parse_mode=HTML).
 * Медиа: sendPhoto / sendVideo multipart с подписью; длина подписи ≤ 1024
 * гарантируется формой слота, Bot API режет multipart-загрузку на 50 МБ —
 * тот же предел стоит на поле загрузки.
 */
class CalendarTelegramPublisher
{
    /**
     * @param  array<string, mixed>  $destination
     * @return array{external_id: string, permalink: ?string, warning: ?string}
     */
    public function publish(ContentCalendarSlot $slot, array $destination): array
    {
        $token = (string) config('content_calendar.tg_bot_token');
        $chatId = (string) ($destination['chat_id'] ?? '');
        if ($token === '' || $chatId === '') {
            throw CalendarPublishException::permanent('telegram: не настроен CONTENT_CAL_TG_BOT_TOKEN или chat_id площадки');
        }

        $text = PostFormatter::toTelegramHtml($slot->body);
        $api = "https://api.telegram.org/bot{$token}";

        $response = match ($slot->mediaType()) {
            ContentCalendarSlot::MEDIA_PHOTO => $this->sendFile($api.'/sendPhoto', 'photo', $slot, $chatId, $text),
            ContentCalendarSlot::MEDIA_VIDEO => $this->sendFile($api.'/sendVideo', 'video', $slot, $chatId, $text, [
                ['name' => 'supports_streaming', 'contents' => 'true'],
            ]),
            default => Http::timeout(30)->asForm()->post($api.'/sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]),
        };

        $messageId = $response->json('result.message_id');
        if (! $response->successful() || $response->json('ok') !== true || $messageId === null) {
            throw new CalendarPublishException(
                'telegram: '.($response->json('description') ?? 'HTTP '.$response->status())
            );
        }

        return [
            'external_id' => (string) $messageId,
            'permalink' => self::permalink($chatId, (int) $messageId),
            'warning' => null,
        ];
    }

    /** @param  list<array{name: string, contents: string}>  $extra */
    private function sendFile(string $url, string $field, ContentCalendarSlot $slot, string $chatId, string $caption, array $extra = []): \Illuminate\Http\Client\Response
    {
        $disk = Storage::disk(ContentCalendarSlot::MEDIA_DISK);
        $path = (string) $slot->media_path;
        if (! $disk->exists($path)) {
            throw CalendarPublishException::permanent("telegram: файл вложения не найден ({$path})");
        }

        return Http::timeout(300)->asMultipart()->post($url, [
            ['name' => 'chat_id', 'contents' => $chatId],
            ['name' => 'caption', 'contents' => $caption],
            ['name' => 'parse_mode', 'contents' => 'HTML'],
            ...$extra,
            ['name' => $field, 'contents' => (string) $disk->get($path), 'filename' => basename($path)],
        ]);
    }

    /** @-username → t.me/name/id; -100… (приватный id) → t.me/c/…/id. */
    public static function permalink(string $chatId, int $messageId): ?string
    {
        if (str_starts_with($chatId, '@')) {
            return 'https://t.me/'.substr($chatId, 1).'/'.$messageId;
        }
        if (str_starts_with($chatId, '-100')) {
            return 'https://t.me/c/'.substr($chatId, 4).'/'.$messageId;
        }

        return null;
    }
}
