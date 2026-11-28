<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Durable cabinet:probe TG state. File cache dies on `optimize:clear`
 * (every deploy.sh) — that reset is what re-sends SOS a minute later (H3197).
 *
 * Path is outside Laravel cache: storage/app/cabinet_probe_tg_state.json
 * (overridable via config cabinet_probe.tg_state_path for tests).
 */
final class CabinetProbeAlertState
{
    public const HTTP_DOWN = 'http_down';

    public const LAST_HTTP_ALERT_AT = 'last_http_alert_at';

    public const LAST_HTTP_FP = 'last_http_fingerprint';

    public const LAST_SOFT_ALERT_AT = 'last_soft_alert_at';

    public const LAST_SOFT_FP = 'last_soft_fingerprint';

    public const SOFT_GREEN_STREAK = 'soft_green_streak';

    public function path(): string
    {
        $configured = (string) config('cabinet_probe.tg_state_path', '');

        return $configured !== '' ? $configured : storage_path('app/cabinet_probe_tg_state.json');
    }

    public function getBool(string $key): bool
    {
        return (bool) ($this->read()[$key] ?? false);
    }

    public function getString(string $key): ?string
    {
        $v = $this->read()[$key] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function getTime(string $key): ?Carbon
    {
        $v = $this->getString($key);
        if ($v === null) {
            return null;
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function put(array $values): void
    {
        $data = $this->read();
        foreach ($values as $k => $v) {
            if ($v instanceof \DateTimeInterface) {
                $data[$k] = Carbon::parse($v->format('c'))->toIso8601String();
            } elseif ($v === null) {
                unset($data[$k]);
            } else {
                $data[$k] = $v;
            }
        }
        $this->write($data);
    }

    public function forget(string ...$keys): void
    {
        $data = $this->read();
        foreach ($keys as $k) {
            unset($data[$k]);
        }
        $this->write($data);
    }

    /**
     * H8562: single-flight для критической секции soft-пути (read → check →
     * send → write). Две перекрывающиеся пробы (тик крона + ручной/деплойный
     * прогон) обе читают «новый отпечаток» до чужой записи → двойной webhook →
     * два issues на один класс. Состояние — файл; лок живет рядом с ним.
     *
     * flock на выделенном lock-файле: межпроцессно на Linux (прод) и Windows
     * (dev), без зависимости от cache-драйвера (урок H3197 — состояние
     * сознательно вне кэша). Открыть лок-файл не удалось → fail-open: сломанный
     * лок не должен гасить алерты; сходимость дубликатов добирается на
     * приемнике (lookup + reconcile в n8n-экспорте H8562).
     *
     * @param  callable(): mixed  $fn
     * @return array{acquired: bool, value: mixed} acquired=false — лок держит другая проба
     */
    public function withSoftLock(callable $fn, ?int $waitSeconds = null): array
    {
        $wait = $waitSeconds ?? (int) config('cabinet_probe.soft_lock_wait_seconds', 10);
        $lockPath = $this->path().'.lock';
        $dir = dirname($lockPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($lockPath, 'c');
        if ($fh === false) {
            return ['acquired' => true, 'value' => $fn()];
        }
        $deadline = microtime(true) + max(0, $wait);
        while (true) {
            if (@flock($fh, LOCK_EX | LOCK_NB)) {
                break;
            }
            if (microtime(true) >= $deadline) {
                @fclose($fh);

                return ['acquired' => false, 'value' => null];
            }
            usleep(100_000);
        }
        try {
            return ['acquired' => true, 'value' => $fn()];
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return $this->migrateFromCache();
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * One-shot lift of the pre-H3197 Cache keys so a deploy that already
     * alerted does not immediately re-SOS after this code lands.
     *
     * @return array<string, mixed>
     */
    private function migrateFromCache(): array
    {
        $data = [];
        if (Cache::get('cabinet_probe:was_down')) {
            $data[self::HTTP_DOWN] = true;
        }
        $lastHttp = Cache::get('cabinet_probe:last_tg_alert_at');
        if ($lastHttp instanceof \DateTimeInterface) {
            $data[self::LAST_HTTP_ALERT_AT] = Carbon::parse($lastHttp->format('c'))->toIso8601String();
        }
        $lastSoft = Cache::get('cabinet_probe:last_soft_tg_alert_at');
        if ($lastSoft instanceof \DateTimeInterface) {
            $data[self::LAST_SOFT_ALERT_AT] = Carbon::parse($lastSoft->format('c'))->toIso8601String();
        }
        $fp = Cache::get('cabinet_probe:last_soft_fingerprint');
        if (is_string($fp) && $fp !== '') {
            $data[self::LAST_SOFT_FP] = $fp;
        }
        if ($data !== []) {
            $this->write($data);
        }

        return $data;
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
