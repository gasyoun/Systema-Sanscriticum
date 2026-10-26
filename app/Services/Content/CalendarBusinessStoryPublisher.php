<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarSlot;
use App\Models\TelegramBusinessConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Картинка для сторис слота → сторис аккаунта, подключённого к Business-боту
 * (Bot API postStory, тот же канал, что TelegramBusinessStoryPublisher для
 * видео каналов). Ссылка — кликабельная область внизу сторис.
 *
 * Сторис не идемпотентна: если ответ Telegram потерян, повтор может дать
 * дубль — такой исход постоянный отказ «проверьте сторис вручную».
 */
class CalendarBusinessStoryPublisher
{
    /** Сокращённый текст поста — подпись, пока пост не вышел (Business = Premium, лимит 2048). */
    private const PREVIEW_LIMIT = 500;

    /**
     * @param  array<string, mixed>  $destination
     * @return array{external_id: string, permalink: ?string, warning: ?string}
     */
    public function publish(ContentCalendarSlot $slot, array $destination): array
    {
        $token = trim((string) config('services.telegram_business.token', ''));
        if ($token === '') {
            throw CalendarPublishException::permanent('сторис: не задан TELEGRAM_BUSINESS_BOT_TOKEN');
        }

        $connection = TelegramBusinessConnection::query()->where('is_enabled', true)->get()
            ->first(fn (TelegramBusinessConnection $c): bool => (bool) ($c->rights['can_manage_stories'] ?? false));
        if ($connection === null) {
            throw CalendarPublishException::permanent('сторис: нет подключения Business с правом can_manage_stories');
        }

        $disk = Storage::disk(ContentCalendarSlot::MEDIA_DISK);
        $path = (string) $slot->story_media_path;
        if ($path === '' || ! $disk->exists($path)) {
            throw CalendarPublishException::permanent("сторис: файл картинки не найден ({$path})");
        }

        $payload = [
            'business_connection_id' => $connection->business_connection_id,
            'content' => json_encode(['type' => 'photo', 'photo' => 'attach://photo']),
            'active_period' => (int) config('services.telegram_business.story_active_period', 86400),
        ] + self::caption($slot);

        try {
            $response = Http::timeout(120)
                ->attach('photo', (string) $disk->get($path), basename($path))
                ->post("https://api.telegram.org/bot{$token}/postStory", $payload);
        } catch (ConnectionException $e) {
            throw CalendarPublishException::permanent('сторис: ответ Telegram потерян — проверьте сторис аккаунта вручную, повтор может дать дубль');
        }

        $id = $response->json('result.id');
        if ($response->serverError() || ($response->successful() && ! is_numeric($id))) {
            throw CalendarPublishException::permanent('сторис: Telegram не подтвердил публикацию — проверьте сторис аккаунта вручную');
        }
        if (! is_numeric($id)) {
            throw new CalendarPublishException('сторис: postStory — '.($response->json('description') ?? 'HTTP '.$response->status()));
        }

        return ['external_id' => (string) $id, 'permalink' => null, 'warning' => null];
    }

    /**
     * Подпись сторис. Пост в «Оповещении» вышел — его первая строка ссылкой
     * на пост (клик по подписи открывает пост); не вышел — сокращённый текст
     * поста без ссылки.
     *
     * @return array{caption: string, parse_mode?: string}
     */
    public static function caption(ContentCalendarSlot $slot): array
    {
        $plain = trim(PostFormatter::toPlain($slot->body));
        $link = CalendarStoryLink::for($slot);
        if ($link === null) {
            return ['caption' => Str::limit($plain, self::PREVIEW_LIMIT, '…', preserveWords: true)];
        }

        $first = Str::limit(trim((string) strtok($plain, "\n")), 200, '…');

        return [
            'caption' => '<a href="'.e($link).'">'.e($first).'</a>',
            'parse_mode' => 'HTML',
        ];
    }
}
