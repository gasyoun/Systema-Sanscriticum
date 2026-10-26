<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarSlot;
use App\Models\MarketingSetting;
use Illuminate\Http\Client\Response;
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
    /** CONTENT_CAL_TG_BOT_TOKEN, иначе @zapisi_ORSbot из настроек маркетинга. */
    public static function botToken(): string
    {
        return (string) (config('content_calendar.tg_bot_token') ?: MarketingSetting::cached()?->zapisi_bot_token ?? '');
    }

    /**
     * @param  array<string, mixed>  $destination
     * @return array{external_id: string, permalink: ?string, warning: ?string}
     */
    public function publish(ContentCalendarSlot $slot, array $destination): array
    {
        $token = self::botToken();
        $chatId = (string) ($destination['chat_id'] ?? '');
        if ($token === '' || $chatId === '') {
            throw CalendarPublishException::permanent('telegram: нет токена бота записей (MarketingSetting.zapisi_bot_token) или chat_id площадки');
        }

        $api = "https://api.telegram.org/bot{$token}";

        // Пост пришёл из TG-канала-источника — копируем оригинал (разметка,
        // медиа и альбом как есть, без лимитов скачивания).
        $source = $slot->meta['tg_source'] ?? null;
        if (is_array($source) && filled($source['chat_id'] ?? null) && ($source['message_ids'] ?? []) !== []) {
            return $this->copy($api, $chatId, (string) $source['chat_id'], array_map('intval', (array) $source['message_ids']));
        }

        $text = PostFormatter::toTelegramHtml($slot->body);

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

    /**
     * @param  list<int>  $messageIds
     * @return array{external_id: string, permalink: ?string, warning: ?string}
     */
    private function copy(string $api, string $chatId, string $fromChatId, array $messageIds): array
    {
        $response = count($messageIds) > 1
            ? Http::timeout(60)->asForm()->post($api.'/copyMessages', [
                'chat_id' => $chatId,
                'from_chat_id' => $fromChatId,
                'message_ids' => json_encode($messageIds),
            ])
            : Http::timeout(60)->asForm()->post($api.'/copyMessage', [
                'chat_id' => $chatId,
                'from_chat_id' => $fromChatId,
                'message_id' => $messageIds[0],
            ]);

        $messageId = $response->json('result.message_id') ?? $response->json('result.0.message_id');
        if (! $response->successful() || $response->json('ok') !== true || $messageId === null) {
            throw new CalendarPublishException('telegram: '.($response->json('description') ?? 'HTTP '.$response->status()));
        }

        return [
            'external_id' => (string) $messageId,
            'permalink' => self::permalink($chatId, (int) $messageId),
            'warning' => null,
        ];
    }

    /** @param  list<array{name: string, contents: string}>  $extra */
    private function sendFile(string $url, string $field, ContentCalendarSlot $slot, string $chatId, string $caption, array $extra = []): Response
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
