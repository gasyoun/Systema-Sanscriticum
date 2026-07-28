<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Считает CSRF-несовпадения (H1773) за окно из JSON-построчного канала
 * csrf_mismatch (config/logging.php), не трогая базу — тот же дух, что у
 * {@see StorageUsageService}: арифметика отдельно от команды, чтобы её могли
 * гонять и тесты, и (в будущем) админ-страница без artisan.
 *
 * Читает файлы Monolog daily-ротации напрямую (`{filename}-{date}.log`,
 * см. Monolog\Handler\RotatingFileHandler) — этот канал не хранит ничего в
 * БД по договору H1360 (game_events: ни IP, ни user-agent, ни user_id), а
 * дайджест обязан оставаться дешёвым построчным парсингом, а не отдельной
 * таблицей.
 */
class CsrfMismatchDigestService
{
    /**
     * @return array{
     *     total: int,
     *     per_route: array<string, int>,
     *     window_days: int,
     *     threshold: int,
     *     ok: bool
     * }
     */
    public function snapshot(int $days): array
    {
        $days = max(1, $days);
        $since = now()->subDays($days);
        $threshold = (int) config('csrf.digest_threshold', 20);

        $perRoute = [];
        $total = 0;

        foreach ($this->readEntries($since) as $entry) {
            $route = $entry['context']['route'] ?? null;
            $route = is_string($route) && $route !== '' ? $route : '(без имени маршрута)';

            $perRoute[$route] = ($perRoute[$route] ?? 0) + 1;
            $total++;
        }

        arsort($perRoute);

        return [
            'total' => $total,
            'per_route' => $perRoute,
            'window_days' => $days,
            'threshold' => $threshold,
            'ok' => $total < $threshold,
        ];
    }

    /**
     * @return list<array{context: array<string, mixed>, datetime: Carbon}>
     */
    private function readEntries(Carbon $since): array
    {
        $entries = [];

        foreach ($this->logFiles() as $path) {
            if (! is_readable($path)) {
                continue;
            }

            $handle = fopen($path, 'r');
            if ($handle === false) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (! is_array($decoded) || ! isset($decoded['datetime'])) {
                    continue; // строка не разобралась — пропускаем, не падаем
                }

                try {
                    $datetime = Carbon::parse($decoded['datetime']);
                } catch (\Throwable) {
                    continue;
                }

                if ($datetime->lt($since)) {
                    continue;
                }

                $entries[] = [
                    'context' => is_array($decoded['context'] ?? null) ? $decoded['context'] : [],
                    'datetime' => $datetime,
                ];
            }

            fclose($handle);
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function logFiles(): array
    {
        $configuredPath = (string) config('logging.channels.csrf_mismatch.path', storage_path('logs/csrf-mismatch.log'));
        $info = pathinfo($configuredPath);
        $pattern = ($info['dirname'] ?? storage_path('logs')).'/'.($info['filename'] ?? 'csrf-mismatch').'-*.'.($info['extension'] ?? 'log');

        return glob($pattern) ?: [];
    }
}
