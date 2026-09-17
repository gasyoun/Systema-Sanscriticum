<?php

declare(strict_types=1);

namespace App\Services\Anons;

use App\Models\AnonsArchiveItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * H5049 R11: индексированный каталог архивных ассетов.
 *
 * Источник — живой stories.getStoriesArchive выбранного аккаунта (не
 * локальный кэш, урок anons-скилла). Скачивание/декодирование/хэши —
 * в подпроцессном воркере (как публикация: Amp-цикл из-под artisan падает).
 * Поиск — по тексту/тегам/хэшам/размерности, БЕЗ повторного скана истории.
 */
final class AnonsArchiveIndexer
{
    public function __construct(
        private readonly SessionHealthProbe $probe,
    ) {}

    /**
     * Проиндексировать архив аккаунта. Возвращает счётчики.
     *
     * @return array{indexed: int, skipped: int, downloaded: int}
     */
    public function indexAccount(string $account = 'rusamskrtam', int $limit = 100): array
    {
        $health = $this->probe->probe($account);
        if (! $health['healthy']) {
            throw new RuntimeException("Archive index: session {$account} is not alive ({$health['reason']}) — never trust a stored session path.");
        }

        $worker = base_path('scripts/anons_archive_worker.php');
        if (! is_file($worker)) {
            throw new RuntimeException("Archive worker not found: {$worker}");
        }

        $result = Process::timeout(max(300, $limit * 5))
            ->run([PHP_BINARY, $worker, (string) json_encode(['account' => $account, 'limit' => $limit], JSON_UNESCAPED_UNICODE)]);

        $payload = null;
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $candidate = json_decode($line, true);
            if (is_array($candidate) && array_key_exists('ok', $candidate)) {
                $payload = $candidate;
                break;
            }
        }

        if (($payload['ok'] ?? null) !== true) {
            throw new RuntimeException('Archive worker failed: '.mb_substr($payload['error'] ?? $result->errorOutput(), 0, 300));
        }

        $indexed = 0;
        $downloaded = 0;
        foreach (($payload['items'] ?? []) as $item) {
            if (! is_array($item) || ! isset($item['remote_id'])) {
                continue;
            }

            $mediaPath = is_string($item['media_path'] ?? null) ? $item['media_path'] : null;
            if ($mediaPath !== null) {
                $downloaded++;
            }

            $attributes = [
                'captured_at' => isset($item['date']) ? date('Y-m-d H:i:s', (int) $item['date']) : now(),
                'media_hash' => $item['media_hash'] ?? null,
                'phash' => $item['phash'] ?? null,
                'dimensions' => isset($item['dimensions']) ? ['w' => (int) $item['dimensions'][0], 'h' => (int) $item['dimensions'][1]] : null,
                'text' => $item['caption'] ?? null,
                'tags' => $this->extractTags($item),
                'destination_url' => $this->extractUrl($item),
                'expires_at' => isset($item['expire_date']) ? date('Y-m-d H:i:s', (int) $item['expire_date']) : null,
                'media_path' => $mediaPath,
            ];

            AnonsArchiveItem::query()->updateOrCreate(
                ['platform' => 'telegram_story', 'account' => $account, 'remote_id' => (string) $item['remote_id']],
                $attributes,
            );
            $indexed++;
        }

        Log::info('Anons archive indexed', ['account' => $account, 'indexed' => $indexed, 'downloaded' => $downloaded]);

        return ['indexed' => $indexed, 'skipped' => max(0, (int) ($payload['total'] ?? 0) - $indexed), 'downloaded' => $downloaded];
    }

    /**
     * Поиск по каталогу: текст/теги/хэш/минимальная давность/текущность.
     *
     * @return list<AnonsArchiveItem>
     */
    public function search(string $query = '', ?string $account = null, bool $currentOnly = true): array
    {
        $q = AnonsArchiveItem::query();
        if ($account !== null) {
            $q->where('account', $account);
        }
        if ($currentOnly) {
            // Текущность: не истёкшая сториз (или вообще не сториз).
            $q->where(static fn ($qq) => $qq->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }
        if ($query !== '') {
            $q->where(static function ($qq) use ($query): void {
                $qq->where('text', 'like', "%{$query}%")
                    ->orWhere('destination_url', 'like', "%{$query}%")
                    ->orWhere('media_hash', $query)
                    ->orWhereJsonContains('tags', mb_strtolower($query));
            });
        }

        return $q->orderByDesc('captured_at')->limit(50)->get()->all();
    }

    /** Пометить использование ассета публикацией (usage history R11). */
    public function markUsage(int $itemId, string $publicationKey): void
    {
        $item = AnonsArchiveItem::query()->findOrFail($itemId);
        $history = $item->usage_history ?? [];
        $history[] = ['key' => $publicationKey, 'at' => now()->toDateTimeString()];
        $item->forceFill(['usage_history' => $history])->save();
    }

    /** @return list<string> */
    private function extractTags(array $item): array
    {
        $text = (string) ($item['caption'] ?? '');
        preg_match_all('/#[a-zа-яё0-9_]+/ui', $text, $m);

        return array_map(static fn ($t) => mb_strtolower(ltrim($t, '#')), array_unique($m[0] ?? []));
    }

    private function extractUrl(array $item): ?string
    {
        if (preg_match('~https?://[^\s]+~i', (string) ($item['url'] ?? ''), $m) === 1) {
            return $m[0];
        }
        if (preg_match('~https://samskrte\.ru/ga/[a-z0-9-]+~i', (string) ($item['caption'] ?? ''), $m2) === 1) {
            return $m2[0];
        }

        return null;
    }
}
