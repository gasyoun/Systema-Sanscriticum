<?php

declare(strict_types=1);

namespace App\Services\Anons\Adapters;

use App\Models\TelegramSupportAccount;
use App\Services\Stories\StoryPublisher;
use App\Services\Telegram\MadelineClientFactory;
use RuntimeException;

/**
 * H5049 R9: адаптер user-сториз Telegram поверх отлаженного StoryPublisher
 * (подпроцессная полоса, peer=me, period 24h). Координаты mediaAreaUrl
 * приходят из ИЗМЕРЕННОЙ плашки (PlaqueBounds::asMediaAreaCoordinates) —
 * клик-зона лежит ровно на отрисованной области.
 */
final class TelegramStoryAdapter implements PlatformAdapter
{
    public function __construct(private readonly StoryPublisher $publisher) {}

    public function platform(): string
    {
        return 'telegram_story';
    }

    public function capabilities(): array
    {
        return [
            'albums' => false, // у user-сториз медиа-групп нет; серия = несколько sendStory
            'series' => true,
            'visible_cta' => true,
            'metrics' => ['views', 'reactions', 'forwards'],
        ];
    }

    /** @param  array<string, mixed>  $frame */
    public function publishFrame(array $frame): array
    {
        $path = (string) ($frame['artifact_path'] ?? '');
        $caption = (string) ($frame['caption'] ?? '');
        $account = (string) ($frame['account'] ?? 'rusamskrtam');
        $link = $frame['link'] ?? null;
        // H5049: ИЗМЕРЕННЫЙ прямоугольник плашки → клик-зона точно на ней.
        $mediaArea = is_array($frame['media_area'] ?? null) ? $frame['media_area'] : null;

        if (! is_file($path)) {
            throw new RuntimeException("TelegramStoryAdapter: artifact missing: {$path}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $storyId = in_array($ext, ['mp4', 'mov'], true)
            ? $this->publisher->sendVideoStory($path, $caption, is_string($link) ? $link : null, $mediaArea)
            : $this->publisher->sendPhotoStory($path, $caption, $account, is_string($link) ? $link : null, $mediaArea);

        return ['id' => $storyId !== null ? (string) $storyId : null, 'raw' => ['story_id' => $storyId]];
    }

    /** @param  list<array<string, mixed>>  $frames */
    public function publishSeries(array $frames): array
    {
        $out = [];
        foreach ($frames as $frame) {
            $out[] = $this->publishFrame($frame);
        }

        return $out;
    }

    public function metrics(string $remoteId, string $account): array
    {
        try {
            $client = $this->client($account);
            $result = $client->stories->getStoriesByID([
                'peer' => 'me',
                'id' => [(int) $remoteId],
            ]);
        } catch (\Throwable $e) {
            // R7: сбой наблюдения — failed, не ноль.
            return $this->allStates('failed', ['views', 'reactions', 'forwards'], ['error' => $e->getMessage()]);
        }

        $story = $this->findStory($result, (int) $remoteId);
        if ($story === null) {
            // История не найдена (истекла/удалена) — данные недоступны.
            return $this->allStates('unavailable', ['views', 'reactions', 'forwards']);
        }

        $views = $story['views'] ?? null;
        if (! is_array($views)) {
            // Поле отсутствует в ответе API — unavailable, НЕ 0 (R7).
            return $this->allStates('unavailable', ['views', 'reactions', 'forwards']);
        }

        $reactions = $views['reactions'] ?? [];
        $forwards = $views['forwards'] ?? null;

        return [
            'views' => ['state' => 'value', 'value' => (int) ($views['views_count'] ?? 0)],
            'reactions' => is_array($reactions)
                ? ['state' => 'value', 'value' => array_sum(array_map(static fn ($r) => (int) ($r['count'] ?? 0), $reactions))]
                : ['state' => 'unavailable', 'value' => null],
            'forwards' => $forwards !== null
                ? ['state' => 'value', 'value' => (int) $forwards]
                : ['state' => 'unavailable', 'value' => null],
        ];
    }

    public function delete(string $remoteId, string $account): void
    {
        $this->publisher->deleteStory((int) $remoteId, $account);
    }

    private function client(string $account): object
    {
        $factory = app(MadelineClientFactory::class);
        if (! $factory->isConfigured()) {
            throw new RuntimeException('MadelineProto is not configured — story metrics unavailable.');
        }

        if ($account === 'rusamskrtam' || $account === '') {
            return $factory->open();
        }

        $row = TelegramSupportAccount::query()->where('name', $account)->where('is_enabled', true)->firstOrFail();

        return $factory->open(null, $row->session_path);
    }

    private function findStory(mixed $result, int $id): ?array
    {
        if (! is_array($result)) {
            return null;
        }
        foreach (($result['stories'] ?? []) as $story) {
            if (is_array($story) && ($story['_'] ?? '') === 'storyItem' && (int) ($story['id'] ?? 0) === $id) {
                return $story;
            }
        }

        return null;
    }

    /** @param  list<string>  $metrics */
    private function allStates(string $state, array $metrics, array $extra = []): array
    {
        $out = [];
        foreach ($metrics as $m) {
            $out[$m] = ['state' => $state, 'value' => null] + $extra;
        }

        return $out;
    }
}
