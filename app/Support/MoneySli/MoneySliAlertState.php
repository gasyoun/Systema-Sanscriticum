<?php

declare(strict_types=1);

namespace App\Support\MoneySli;

use Carbon\Carbon;

/**
 * H4672 money-axis SLI TG cooldown state — same durable-file shape as
 * App\Support\ServerGuards\CabinetProbeAlertState (H3197: file cache, not
 * Laravel Cache, so a deploy's optimize:clear can't cause a spurious re-alert),
 * kept separate because it tracks different fingerprint classes
 * (synthetic-pay vs hourly-reconcile) that must cool down independently.
 */
final class MoneySliAlertState
{
    public const LAST_ALERT_AT_PREFIX = 'last_alert_at:';

    public const LAST_FP_PREFIX = 'last_fp:';

    public function path(): string
    {
        $configured = (string) config('money_sli.tg_state_path', '');

        return $configured !== '' ? $configured : storage_path('app/money_sli_tg_state.json');
    }

    /**
     * Should an alert for $checkKey/$fingerprint be sent now, given the
     * reminder-hours cooldown? Does not itself record the send — call
     * recordSent() after a successful TG delivery.
     */
    public function shouldAlert(string $checkKey, string $fingerprint, int $reminderHours, bool $force): bool
    {
        if ($force) {
            return true;
        }

        $data = $this->read();
        $lastFp = $data[self::LAST_FP_PREFIX.$checkKey] ?? null;
        $lastAt = $data[self::LAST_ALERT_AT_PREFIX.$checkKey] ?? null;

        if (! is_string($lastFp) || $lastFp !== $fingerprint || ! is_string($lastAt)) {
            return true;
        }

        if ($reminderHours <= 0) {
            return false;
        }

        try {
            $elapsedHours = (int) now()->diffInHours(Carbon::parse($lastAt), absolute: true);
        } catch (\Throwable) {
            return true;
        }

        return $elapsedHours >= $reminderHours;
    }

    public function recordSent(string $checkKey, string $fingerprint): void
    {
        $data = $this->read();
        $data[self::LAST_FP_PREFIX.$checkKey] = $fingerprint;
        $data[self::LAST_ALERT_AT_PREFIX.$checkKey] = now()->toIso8601String();
        $this->write($data);
    }

    public function clear(string $checkKey): void
    {
        $data = $this->read();
        unset($data[self::LAST_FP_PREFIX.$checkKey], $data[self::LAST_ALERT_AT_PREFIX.$checkKey]);
        $this->write($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(array $data): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return;
        }
        $tmp = $path.'.tmp';
        $ok = @file_put_contents($tmp, $json, LOCK_EX);
        if ($ok === false) {
            return;
        }
        @rename($tmp, $path);
    }
}
