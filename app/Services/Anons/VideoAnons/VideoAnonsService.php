<?php

declare(strict_types=1);

namespace App\Services\Anons\VideoAnons;

use App\Jobs\DistributeVideoAnonsJob;
use App\Support\TelegramSendGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * H6546: лейн «видео-анонс» — фильтр и роутинг.
 *
 * Вызывается из ProcessTelegramZapisiUpdate на КАЖДОЕ сообщение учебного чата:
 * video от автора из белого списка (config/video-anons.php) в чате из карты
 * курс→чаты диспатчит рассылку (DistributeVideoAnonsJob), всё остальное
 * молча игнорирует. Кружки (video_note) сознательно не триггерят — это личное
 * адресное сообщение, не видеопост (в корпусе оба идут media_type=video).
 *
 * С 12-10-2026 (вечер, вердикт MG по живой пробе) авто-копии по чатам выключены
 * (copies_enabled=false): лейн = приёмка видео + черновик MG, из которого по
 * заголовку MG уходит сторис персоны (@rusamskrtam, VideoAnonsStoriesService).
 *
 * Дедуп на клейме (TelegramSendGuard): повторный заход того же сообщения
 * (ределивери вебхука, ручной replay) подавляется; клейм короткий — джоб
 * после успешной рассылки продлевает его (схема «клейм на время полёта»).
 */
class VideoAnonsService
{
    /** Окно «полёта» рассылки: дольше — ретраи очереди, короче — нет. */
    private const LANE_CLAIM_TTL = 3600;

    public function handle(array $message): void
    {
        if (! config('video-anons.enabled')) {
            return;
        }

        if (! isset($message['video']) || ! is_array($message['video'])) {
            return;
        }

        $course = $this->courseForChat((int) ($message['chat']['id'] ?? 0));
        if ($course === null) {
            return;
        }

        if (! $this->authorAllowed($message['from'] ?? null, $course['key'])) {
            return;
        }

        $chatId = (int) $message['chat']['id'];
        $messageId = (int) $message['message_id'];
        $dateTs = (int) ($message['date'] ?? 0);
        $laneKey = sprintf('video-anons:lane:%d:%d', $chatId, $messageId);
        if (! TelegramSendGuard::claimKey($laneKey, self::LANE_CLAIM_TTL)) {
            Log::info('VideoAnons: video already distributed, duplicate suppressed', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);

            return;
        }

        $targets = config('video-anons.copies_enabled')
            ? array_values(array_diff($course['chat_ids'], [$chatId]))
            : [];
        $mediaPath = $this->mediaPathFor($chatId, $messageId, $dateTs);

        DistributeVideoAnonsJob::dispatch($chatId, $messageId, $course['key'], $targets, $laneKey, $mediaPath);

        Log::info('VideoAnons: distribution dispatched', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'course' => $course['key'],
            'copies_enabled' => (bool) config('video-anons.copies_enabled'),
            'targets' => $targets,
            'media_path' => $mediaPath,
        ]);
    }

    /**
     * Карта курс→чаты из config/video-anons.php. Чат вне карты = лейн молчит:
     * авто-пост разрешён только для учебных чатов из карты (решение 3).
     *
     * @return null|array{key: string, title: string, chat_ids: array<int, int>}
     */
    public function courseForChat(int $chatId): ?array
    {
        foreach ((array) config('video-anons.courses', []) as $key => $entry) {
            $chatIds = array_map('intval', (array) ($entry['chat_ids'] ?? []));
            if (in_array($chatId, $chatIds, true)) {
                return [
                    'key' => (string) $key,
                    'title' => (string) ($entry['title'] ?? $key),
                    'chat_ids' => $chatIds,
                ];
            }
        }

        return null;
    }

    /**
     * Белый список авторов (решение 3): MG, Иван Молочко, преподаватель курса.
     * ids каноничны, username — fallback; course_authors добавляет пер-курсовых.
     *
     * @param  array<string, mixed>|null  $from
     */
    public function authorAllowed(?array $from, string $courseKey): bool
    {
        if ($from === null) {
            return false;
        }

        $allowedIds = array_map('intval', (array) config('video-anons.author_ids', []));
        $allowedUsernames = array_map(
            'strtolower',
            array_map('trim', (array) config('video-anons.author_usernames', [])),
        );
        $allowedIds = array_merge(
            $allowedIds,
            array_map('intval', (array) config("video-anons.course_authors.{$courseKey}", [])),
        );

        $userId = (int) ($from['id'] ?? 0);
        $username = strtolower(trim((string) ($from['username'] ?? '')));

        return in_array($userId, $allowedIds, true) || ($username !== '' && in_array($username, $allowedUsernames, true));
    }

    /**
     * Локальный путь скачанного медиа (DownloadTelegramZapisiMedia пишет
     * {store}/zapisi_media/{chat}/{date}/{msg}_{type}.{ext}); расширение
     * не фиксировано — glob. null = файл ещё не скачан/не найден (сторис
     * тогда не собрать, но черновик всё равно уходит).
     */
    public function mediaPathFor(int $chatId, int $messageId, int $dateTs): ?string
    {
        if ($dateTs <= 0) {
            return null;
        }

        $base = (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));
        $date = CarbonImmutable::createFromTimestamp($dateTs, config('app.timezone'))->toDateString();

        $candidates = glob($base."/zapisi_media/{$chatId}/{$date}/{$messageId}_video.*") ?: [];

        return $candidates === [] ? null : $candidates[0];
    }
}
