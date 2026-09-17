<?php

declare(strict_types=1);

namespace App\Services\Stories;

use App\Models\TelegramSupportAccount;
use App\Services\Telegram\MadelineClientFactory;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Отправитель user-сториз персоны @rusamskrtam (H3964, Phase 2).
 *
 * ЕДИНАЯ MadelineProto-сессия (легаси support-сессия и есть аккаунт
 *
 * @rusamskrtam, getSelf id=5487293147, H3380); открывать её вправе только
 * тот, кто уже держит madeline-session-лок (LocksMadelineSession) — здесь
 * лок НЕ берётся.
 *
 * Два исполнения одного и того же набора вызовов:
 *  - ПОДПРОЦЕССНЫЙ (дефолт на реальном хосте, services.telegram_story.
 *    subprocess_lane): каждый MTProto-вызов исполняет scripts/
 *    stories_lane_worker.php в изолированном процессе. Почему: из-под
 *    artisan второй заход в Amp-цикл MP v8 падает с Revolt DriverSuspension
 *    (живой замер 03-09-2026), а standalone-процесс работает чисто.
 *    Родитель держит лок и watchdog, воркеру хватает stories_timeout_seconds.
 *  - ПРЯМОЙ (тесты): клиент открывается здесь — в тестах это фейк из
 *    services.telegram_support.client_class, DriverSuspension там не грозит.
 *
 * Формы вызова (в воркере/прямо): плоский массив параметров верхнего уровня
 * MadelineProto. Медиа по TL-схеме: фото — inputMediaUploadedPhoto от
 * $client->upload(), видео — inputMediaUploadedDocument с
 * documentAttributeVideo; peer «me», period 24 ч.
 *
 * ТЕКСТОВЫХ user-сториз в MTProto НЕТ: в TL-схеме layer 225 (и у вендорного
 * MadelineProto, и в актуальном tdlib) конструктора text-медиа не существует,
 * а inputMediaEmpty живой сервер отвечает MEDIA_FILE_INVALID (замер
 * 03-09-2026, H3964 unit 1). «Текстовый» контент персоны — пост канала
 * (stories:publish-due, Phase 1).
 */
class StoryPublisher
{
    /** Сутки — дефолтный срок жизни сториз (period, секунды). */
    private const PERIOD_24H = 86400;

    public function __construct(private readonly MadelineClientFactory $factory) {}

    /** Подпроцессная полоса включена (реальный хост; в тестах выключена). */
    public function viaSubprocess(): bool
    {
        return (bool) config('services.telegram_story.subprocess_lane', true);
    }

    public function sendPhotoStory(string $absolutePath, string $caption = '', ?string $account = null, ?string $link = null): ?int
    {
        if ($this->viaSubprocess()) {
            return $this->execWorker(['action' => 'send_photo', 'path' => $absolutePath, 'caption' => $caption, 'account' => $account, 'link' => $link]);
        }

        return $this->sendPhotoStoryDirect($absolutePath, $caption, $account, $link);
    }

    public function sendVideoStory(string $absolutePath, string $caption = '', ?string $link = null): ?int
    {
        if ($this->viaSubprocess()) {
            return $this->execWorker(['action' => 'send_video', 'path' => $absolutePath, 'caption' => $caption, 'link' => $link]);
        }

        return $this->sendVideoStoryDirect($absolutePath, $caption, $link);
    }

    public function deleteStory(int $storyId, ?string $account = null): void
    {
        if ($this->viaSubprocess()) {
            $this->execWorker(['action' => 'delete', 'story_id' => $storyId, 'account' => $account]);

            return;
        }

        $this->deleteStoryDirect($storyId, $account);
    }

    /**
     * Текстовых user-сториз в MTProto-схеме не существует (см. докблок класса):
     * метод оставлен как явная точка отказа, чтобы никто не «починил» его
     * молчаливой публикацией поста вместо сториз.
     */
    public function sendTextStory(string $text): never
    {
        throw new RuntimeException(
            'Text user-stories are not supported by the MTProto stories schema '
            .'(no text InputMedia constructor; inputMediaEmpty → MEDIA_FILE_INVALID, live 03-09-2026). '
            .'Use a channel post (stories:publish-due) for text.'
        );
    }

    // --- Прямое исполнение (воркер и тесты) ---

    /** Фотосториз из локального файла. Возвращает id сториз или null. */
    public function sendPhotoStoryDirect(string $absolutePath, string $caption = '', ?string $account = null, ?string $link = null): ?int
    {
        $client = $this->client($account);

        return $this->send($client, [
            '_' => 'inputMediaUploadedPhoto',
            'file' => $this->upload($client, $absolutePath),
        ], $caption, $link);
    }

    /** Видеосториз из локального файла (mp4/mov). */
    public function sendVideoStoryDirect(string $absolutePath, string $caption = '', ?string $link = null): ?int
    {
        $client = $this->client();

        return $this->send($client, [
            '_' => 'inputMediaUploadedDocument',
            'file' => $this->upload($client, $absolutePath),
            'mime_type' => $this->videoMime($absolutePath),
            'attributes' => [
                ['_' => 'documentAttributeVideo'],
            ],
        ], $caption, $link);
    }

    /**
     * Удалить свою сториз по id. Имя метода — deleteStories (множественное):
     * stories.deleteStory в схеме MP v8 НЕТ.
     */
    public function deleteStoryDirect(int $storyId, ?string $account = null): void
    {
        $this->client($account)->stories->deleteStories([
            'peer' => 'me',
            'id' => [$storyId],
        ]);
    }

    /**
     * Единая точка вызова stories.sendStory: peer «я» (свой профиль —
     * рулинг MG «персона + канал»: сториз кладёт persona-аккаунт без
     * админ-прав канала), срок 24 ч.
     *
     * @param  array<string, mixed>  $media
     */
    private function send(object $client, array $media, string $caption, ?string $link = null): ?int
    {
        $entities = null;
        if ($link !== null && filter_var($link, FILTER_VALIDATE_URL) !== false) {
            $byteOffset = strpos($caption, $link);
            if ($byteOffset === false) {
                $prefix = $caption !== '' ? $caption."\n" : '';
                $byteOffset = strlen($prefix);
                $caption = $prefix.$link;
            }

            $utf16Prefix = mb_convert_encoding(substr($caption, 0, $byteOffset), 'UTF-16LE', 'UTF-8');
            $entities = [[
                '_' => 'messageEntityUrl',
                'offset' => intdiv(strlen($utf16Prefix), 2),
                'length' => strlen($link),
            ]];
        }

        $params = [
            'peer' => 'me',
            'media' => $media,
            'caption' => $caption !== '' ? $caption : null,
            'entities' => $entities,
            'random_id' => random_int(0, PHP_INT_MAX),
            'period' => self::PERIOD_24H,
        ];

        if ($link !== null && filter_var($link, FILTER_VALIDATE_URL) !== false) {
            $params['media_areas'] = [[
                '_' => 'mediaAreaUrl',
                'coordinates' => [
                    '_' => 'mediaAreaCoordinates',
                    'x' => 50.0,
                    'y' => 55.0,
                    'w' => 78.0,
                    'h' => 14.0,
                    'rotation' => 0.0,
                    'radius' => 7.0,
                ],
                'url' => $link,
            ]];
        }

        $result = $client->stories->sendStory($params);

        return $this->extractStoryId($result);
    }

    /**
     * Достать id опубликованной сториз из Updates:stories.sendStory
     * отдаёт updateStory (story:StoryItem с id) — форма извлечения та же,
     * что у extractSentMessageId (updateMessageID) в support-синке.
     */
    private function extractStoryId(mixed $result): ?int
    {
        if (! is_array($result)) {
            return null;
        }

        foreach (($result['updates'] ?? []) as $update) {
            if (is_array($update)
                && ($update['_'] ?? null) === 'updateStory'
                && isset($update['story']['id'])) {
                return (int) $update['story']['id'];
            }
        }

        if (isset($result['story']['id'])) {
            return (int) $result['story']['id'];
        }

        return isset($result['id']) ? (int) $result['id'] : null;
    }

    private function client(?string $account = null): object
    {
        if (! $this->factory->isConfigured()) {
            throw new RuntimeException(
                'MadelineProto is not configured (services.telegram_support.api_id/api_hash/client_class).'
            );
        }

        if ($account === null || $account === 'rusamskrtam') {
            return $this->factory->open();
        }

        $row = TelegramSupportAccount::query()->where('name', $account)->where('is_enabled', true)->firstOrFail();

        return $this->factory->open(null, $row->session_path);
    }

    /**
     * Загрузить файл в Telegram (InputFile/InputFileBig) — одна загрузка
     * на сториз, переиспользования загрузок здесь нет.
     *
     * @return array<string, mixed>
     */
    private function upload(object $client, string $absolutePath): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException("Story media file is missing or unreadable: {$absolutePath}");
        }

        $uploaded = $client->upload($absolutePath);
        if (! is_array($uploaded)) {
            throw new RuntimeException('MadelineProto upload() returned a non-array InputFile.');
        }

        return $uploaded;
    }

    private function videoMime(string $path): string
    {
        return str_ends_with(strtolower($path), '.mov')
            ? 'video/quicktime'
            : 'video/mp4';
    }

    /**
     * Исполнить одну задачу воркером. Родитель держит madeline-session-лок
     * и watchdog — воркеру передаётся потолок тем же stories_timeout_seconds.
     *
     * @param  array<string, mixed>  $task
     */
    private function execWorker(array $task): ?int
    {
        $worker = base_path('scripts/stories_lane_worker.php');
        if (! is_file($worker)) {
            throw new RuntimeException("Stories lane worker not found: {$worker}");
        }

        $timeout = max(30, (int) config('services.telegram_story.stories_timeout_seconds', 120));

        $result = Process::timeout($timeout)
            ->run([PHP_BINARY, $worker, (string) json_encode($task, JSON_UNESCAPED_UNICODE)]);

        $lines = array_values(array_filter(explode("\n", trim($result->output()))));
        $payload = null;
        foreach ($lines as $line) {
            $candidate = json_decode($line, true);
            if (is_array($candidate) && isset($candidate['ok'])) {
                $payload = $candidate;
                break;
            }
        }

        if (! is_array($payload) || ! isset($payload['ok'])) {
            throw new RuntimeException('Stories lane worker produced no JSON verdict: '
                .mb_substr($result->errorOutput() !== '' ? $result->errorOutput() : $result->output(), 0, 300));
        }

        if ($payload['ok'] !== true) {
            throw new RuntimeException('Stories lane worker failed: '.(string) $payload['error']);
        }

        return isset($payload['story_id']) && $payload['story_id'] !== null
            ? (int) $payload['story_id']
            : null;
    }
}
