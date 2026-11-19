<?php

declare(strict_types=1);

namespace App\Services\Anons\Adapters;

use App\Models\MarketingSetting;
use App\Services\Messaging\TelegramDeliveryChannel;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * H5049 R9: адаптер канальных постов Telegram (Bot API, магнит-бот
 * из MarketingSetting — один бот на одну поверхность, FINDINGS §651).
 * Альбомы (media groups) поддерживаются по capability; фото-альбом
 * 2..10 медиа одним sendMessage-вызовом.
 *
 * H6915: видео-лейн (kind=video) — sendVideo с caption+parse_mode=HTML.
 * Лимит Bot API на multipart-загрузку 50 МБ проверяется ДО отправки,
 * отказ с понятной ошибкой (fail-closed, слот не закрывается).
 */
final class TelegramPostAdapter implements PlatformAdapter
{
    /** Bot API: максимальный размер файла при стандартной multipart-загрузке. */
    public const MAX_VIDEO_BYTES = 50 * 1024 * 1024;

    public function __construct(private readonly TelegramDeliveryChannel $channel) {}

    public function platform(): string
    {
        return 'telegram_post';
    }

    public function capabilities(): array
    {
        return [
            'albums' => true,
            'series' => true,
            'visible_cta' => false, // пост-канал: ссылка в тексте, плашки нет
            'metrics' => [], // Bot API канальных views без доп. токена не отдаёт — not_supported
        ];
    }

    /** @param  array<string, mixed>  $frame */
    public function publishFrame(array $frame): array
    {
        $token = MarketingSetting::magnetBotToken();
        $chatId = config('services.telegram_story.channel_chat_id');
        if (! is_string($token) || $token === '' || ! is_string($chatId) || $chatId === '') {
            throw new RuntimeException('telegram_post: channel credentials are not configured.');
        }

        $caption = (string) ($frame['caption'] ?? '');
        $link = is_string($frame['link'] ?? null) ? (string) $frame['link'] : null;
        if ($link !== null && $caption !== '' && str_contains($caption, $link) === false) {
            $caption .= "\n".$link;
        }

        if (($frame['kind'] ?? 'photo') === 'video') {
            return $this->sendVideo($token, $chatId, (string) ($frame['asset_path'] ?? ''), $caption);
        }

        $photo = (string) ($frame['asset_path'] ?? '');
        $response = Http::asMultipart()->post(
            "https://api.telegram.org/bot{$token}/sendPhoto",
            [
                ['name' => 'chat_id', 'contents' => $chatId],
                ['name' => 'caption', 'contents' => $caption],
                ['name' => 'photo', 'contents' => fopen($photo, 'rb') ?: throw new RuntimeException("Cannot read {$photo}")],
            ]
        );
        $payload = $response->json() ?? [];
        $messageId = data_get($payload, 'result.message_id');
        if ($messageId === null) {
            throw new RuntimeException('telegram_post: sendPhoto returned no message_id: '.mb_substr($response->body(), 0, 200));
        }

        return ['id' => (string) $messageId, 'raw' => $payload];
    }

    /**
     * H6915: канальный видео-пост — sendVideo с подписью (parse_mode=HTML).
     * Лимит 50 МБ проверяется до загрузки: превышение — понятный отказ,
     * слот не закрывается (журнал получает причину, повтор уместен после
     * замены файла, дублей нет).
     *
     * @return array{id: string, raw: array<string, mixed>}
     */
    private function sendVideo(string $token, string $chatId, string $videoPath, string $caption): array
    {
        if ($videoPath === '' || ! is_file($videoPath) || ! is_readable($videoPath)) {
            throw new RuntimeException("telegram_post: video media missing/unreadable: {$videoPath}");
        }
        $bytes = (int) filesize($videoPath);
        if ($bytes > self::MAX_VIDEO_BYTES) {
            throw new RuntimeException(sprintf(
                'telegram_post: video exceeds Bot API 50MB upload limit (%.1f MB > 50 MB): %s — re-encode or trim the fragment, slot stays open.',
                $bytes / 1024 / 1024,
                $videoPath
            ));
        }

        $response = Http::asMultipart()->post(
            "https://api.telegram.org/bot{$token}/sendVideo",
            [
                ['name' => 'chat_id', 'contents' => $chatId],
                ['name' => 'caption', 'contents' => $caption],
                ['name' => 'parse_mode', 'contents' => 'HTML'],
                ['name' => 'video', 'contents' => fopen($videoPath, 'rb') ?: throw new RuntimeException("Cannot read {$videoPath}")],
            ]
        );
        $payload = $response->json() ?? [];
        $messageId = data_get($payload, 'result.message_id');
        if ($messageId === null) {
            throw new RuntimeException('telegram_post: sendVideo returned no message_id: '.mb_substr($response->body(), 0, 200));
        }

        return ['id' => (string) $messageId, 'raw' => $payload];
    }

    /** @param  list<array<string, mixed>>  $frames */
    public function publishSeries(array $frames): array
    {
        // Telegram-альбом: один sendMediaGroup на 2..10 фото (Bot API).
        // Видео-кадры в альбом не ходят (photo-only media group) — серия с
        // видео публикуется по-кадрово.
        $hasVideo = (bool) array_filter($frames, static fn (array $f): bool => ($f['kind'] ?? 'photo') === 'video');
        if (! $hasVideo && count($frames) >= 2 && count($frames) <= 10) {
            return $this->sendMediaGroup($frames);
        }

        $out = [];
        foreach ($frames as $frame) {
            $out[] = $this->publishFrame($frame);
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $frames */
    private function sendMediaGroup(array $frames): array
    {
        $token = MarketingSetting::magnetBotToken();
        $chatId = (string) config('services.telegram_story.channel_chat_id');

        $media = [];
        $multipart = [['name' => 'chat_id', 'contents' => $chatId]];
        foreach (array_values($frames) as $i => $frame) {
            $photo = (string) $frame['asset_path'];
            $attach = "attach://photo{$i}";
            $media[] = [
                'type' => 'photo',
                'media' => $attach,
                'caption' => $i === 0 ? $this->withLink($frame) : null,
                'parse_mode' => 'HTML',
            ];
            $multipart[] = ['name' => "photo{$i}", 'contents' => fopen($photo, 'rb') ?: throw new RuntimeException("Cannot read {$photo}")];
        }
        $multipart[] = ['name' => 'media', 'contents' => json_encode(array_map(
            static fn ($m) => array_filter($m, static fn ($v) => $v !== null),
            $media
        ), JSON_UNESCAPED_UNICODE)];

        $response = Http::asMultipart()->post("https://api.telegram.org/bot{$token}/sendMediaGroup", $multipart);
        $payload = $response->json() ?? [];
        $group = data_get($payload, 'result');
        if (! is_array($group) || count($group) !== count($frames)) {
            throw new RuntimeException('telegram_post: sendMediaGroup mismatch: '.mb_substr($response->body(), 0, 200));
        }

        return array_map(static fn ($m) => ['id' => (string) $m['message_id'], 'raw' => $m], $group);
    }

    /** @param  array<string, mixed>  $frame */
    private function withLink(array $frame): string
    {
        $caption = (string) ($frame['caption'] ?? '');
        $link = is_string($frame['link'] ?? null) ? (string) $frame['link'] : null;

        return ($caption !== '' && $link !== null && str_contains($caption, $link) === false)
            ? $caption."\n".$link
            : $caption;
    }

    public function metrics(string $remoteId, string $account): array
    {
        return ['views' => ['state' => 'not_supported', 'value' => null]];
    }

    public function delete(string $remoteId, string $account): void
    {
        $token = MarketingSetting::magnetBotToken();
        $chatId = (string) config('services.telegram_story.channel_chat_id');
        $response = Http::asForm()->post("https://api.telegram.org/bot{$token}/deleteMessage", [
            'chat_id' => $chatId, 'message_id' => $remoteId,
        ]);
        if (($response->json('ok') ?? false) !== true) {
            throw new RuntimeException('telegram_post: deleteMessage failed: '.mb_substr($response->body(), 0, 200));
        }
    }
}
