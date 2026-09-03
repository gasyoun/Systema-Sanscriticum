<?php

declare(strict_types=1);

namespace App\Services\Stories;

use App\Services\Telegram\MadelineClientFactory;
use RuntimeException;

/**
 * Отправитель user-сториз персоны @rusamskrtam (H3964, Phase 2).
 *
 * Работает поверх ЕДИНОЙ MadelineProto-сессии (MadelineClientFactory —
 * легаси-сессия и есть аккаунт @rusamskrtam, getSelf id=5487293147, H3380).
 * Открывать клиента имеет право только вызывающий, уже держащий
 * madeline-session-лок (LocksMadelineSession) — здесь лок НЕ берётся.
 *
 * Формы вызова — как у TelegramSupportSyncService::deliverMessage():
 * плоский массив параметров верхнего уровня MadelineProto
 * ($client->stories->sendStory([...])). Медиа строится по TL-схеме:
 * фото — inputMediaUploadedPhoto от $client->upload(), видео —
 * inputMediaUploadedDocument с documentAttributeVideo; текстовая сториз —
 * inputMediaEmpty + caption (user stories без медиа). Живой прогон
 * (H3964 unit 1) подтверждает/корректирует формы — расхождение ловит
 * RPCErrorException, а не молчание.
 */
class StoryPublisher
{
    /** Сутки — дефолтный срок жизни сториз (period, секунды). */
    private const PERIOD_24H = 86400;

    public function __construct(private readonly MadelineClientFactory $factory) {}

    /**
     * Текстовая сториз на СВОЙ профиль. Возвращает id сториз
     * (для последующего stories.deleteStory) или null, если id из
     * ответа достать не удалось (публикация при этом состоялась).
     */
    public function sendTextStory(string $text): ?int
    {
        return $this->send($this->client(), ['_' => 'inputMediaEmpty'], $text);
    }

    /** Фотосториз из локального файла. */
    public function sendPhotoStory(string $absolutePath, string $caption = ''): ?int
    {
        $client = $this->client();

        return $this->send($client, [
            '_' => 'inputMediaUploadedPhoto',
            'file' => $this->upload($client, $absolutePath),
        ], $caption);
    }

    /** Видеосториз из локального файла (mp4/mov). */
    public function sendVideoStory(string $absolutePath, string $caption = ''): ?int
    {
        $client = $this->client();

        return $this->send($client, [
            '_' => 'inputMediaUploadedDocument',
            'file' => $this->upload($client, $absolutePath),
            'mime_type' => $this->videoMime($absolutePath),
            'attributes' => [
                ['_' => 'documentAttributeVideo'],
            ],
        ], $caption);
    }

    /** Удалить свою сториз по id (уборка тестовых/смоковых артефактов). */
    public function deleteStory(int $storyId): void
    {
        $this->client()->stories->deleteStory([
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
    private function send(object $client, array $media, string $caption): ?int
    {
        $result = $client->stories->sendStory([
            'peer' => 'me',
            'media' => $media,
            'caption' => $caption !== '' ? $caption : null,
            'random_id' => random_int(0, PHP_INT_MAX),
            'period' => self::PERIOD_24H,
        ]);

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

    private function client(): object
    {
        if (! $this->factory->isConfigured()) {
            throw new RuntimeException(
                'MadelineProto is not configured (services.telegram_support.api_id/api_hash/client_class).'
            );
        }

        return $this->factory->open();
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
}
