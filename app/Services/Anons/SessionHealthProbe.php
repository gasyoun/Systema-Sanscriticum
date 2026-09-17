<?php

declare(strict_types=1);

namespace App\Services\Anons;

use App\Services\Telegram\MadelineSyncPhase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * H5049 R13: живое здоровье MadelineProto-сессии ДО публикации.
 * Путь к файлу сессии на диске ≠ живой сеанс: probe выполняет реальный
 * MTProto-вызов getSelf в подпроцессном воркере (та же полоса, что у
 * StoryPublisher — DriverSuspension из-под artisan, замер 03-09-2026).
 *
 * Классификация ошибки: FLOOD_WAIT_N → транзиент с окном повтора N секунд;
 * AUTH_KEY_UNREGISTERED/SESSION_REVOKED/UNAUTHORIZED → переавторизация
 * нужна человеку; всё остальное — транзиент c дефолтным backoff.
 */
final class SessionHealthProbe
{
    public const DEFAULT_BACKOFF = 300;

    /** @return array{healthy: bool, account: string, reason: ?string, retry_after: ?int, needs_reauth: bool} */
    public function probe(string $account = 'rusamskrtam'): array
    {
        // Пост-таймаутный cooldown — та же сессия у support/harvest/stories.
        if (MadelineSyncPhase::cooldownActive()) {
            return ['healthy' => false, 'account' => $account, 'reason' => 'post-timeout cooldown active',
                'retry_after' => (int) config('services.telegram_harvest.sync_timeout_cooldown_seconds', 600), 'needs_reauth' => false];
        }

        $worker = base_path('scripts/stories_lane_worker.php');
        if (! is_file($worker)) {
            throw new RuntimeException("Stories lane worker not found: {$worker}");
        }

        $result = Process::timeout((int) config('services.telegram_story.stories_timeout_seconds', 120))
            ->run([PHP_BINARY, $worker, (string) json_encode([
                'action' => 'get_self', 'account' => $account,
            ], JSON_UNESCAPED_UNICODE)]);

        $payload = $this->verdict($result->output());
        if ($payload === null) {
            return ['healthy' => false, 'account' => $account,
                'reason' => 'no JSON verdict from lane worker: '.mb_substr($result->errorOutput() !== '' ? $result->errorOutput() : $result->output(), 0, 300),
                'retry_after' => self::DEFAULT_BACKOFF, 'needs_reauth' => false];
        }

        if (($payload['ok'] ?? false) === true) {
            return ['healthy' => true, 'account' => $account, 'reason' => null, 'retry_after' => null, 'needs_reauth' => false];
        }

        $error = (string) ($payload['error'] ?? 'unknown');
        $classification = $this->classify($error);

        Log::warning('Anons session health probe failed', ['account' => $account, 'error' => $error, 'class' => $classification]);

        return [
            'healthy' => false, 'account' => $account, 'reason' => $error,
            'retry_after' => $classification['retry_after'],
            'needs_reauth' => $classification['needs_reauth'],
        ];
    }

    /** @return array{retry_after: ?int, needs_reauth: bool} */
    public function classify(string $error): array
    {
        if (preg_match('/FLOOD(?:_WAIT)?[_ ]?(\d+)/i', $error, $m) === 1) {
            return ['retry_after' => min((int) $m[1], 3600), 'needs_reauth' => false];
        }
        if (preg_match('/AUTH_KEY_UNREGISTERED|SESSION_REVOKED|UNAUTHORIZED|USER_DEACTIVATED/i', $error) === 1) {
            return ['retry_after' => null, 'needs_reauth' => true];
        }

        return ['retry_after' => self::DEFAULT_BACKOFF, 'needs_reauth' => false];
    }

    private function verdict(string $output): ?array
    {
        foreach (array_filter(explode("\n", trim($output))) as $line) {
            $candidate = json_decode($line, true);
            if (is_array($candidate) && array_key_exists('ok', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
