<?php

/**
 * ops_lock.php — standalone O_EXCL + TTL write-lock helper for prod ad-hoc scripts.
 *
 * WHY: H5195 dual-run (20-09-2026) — two agent sessions wrote the same prod
 * tables in parallel. Ruling: a server-side O_EXCL+TTL lock gates money-surface
 * write scripts on .92. No framework bootstrap: plain PHP 8.x single file, so
 * tinker/fix/ad-hoc scripts can call it before mutating.
 *
 * USAGE:
 *   ops_lock.php acquire <slug> [ttl_minutes=120]   # exit 0, prints lock path; exit 1 if held (holder JSON -> stderr)
 *   ops_lock.php release <slug> <claimed_by>        # unlinks only on exact claimed_by match; no --force exists
 *   ops_lock.php status [slug]                      # JSON list of lock(s) with remaining_seconds
 *   ops_lock.php sweep                              # delete expired locks, print count
 *
 * Ad-hoc convention (перед мутацией прод-данных):
 *   php /usr/local/bin/ops_lock acquire <slug> 120 || exit 1
 *   ... мутация ...
 *   php /usr/local/bin/ops_lock release <slug> <claimed_by>
 *   Упавший процесс оставляет замок, который протухает по TTL (sweep в cron не
 *   обязателен — acquire заменяет протухший сам).
 *
 * Exit codes: 0 ok · 1 refused/conflict · 2 usage error.
 * Lock dir: env OPS_LOCK_DIR, else /var/www/html/storage/ops-locks (0775).
 * Slug: [A-Za-z0-9._-]+ only (path traversal rejected, exit 2).
 */

declare(strict_types=1);

const EXIT_OK = 0;
const EXIT_CONFLICT = 1;
const EXIT_USAGE = 2;

function fail(int $code, string $msg): void
{
    fwrite(STDERR, "ops_lock: {$msg}\n");
    exit($code);
}

function lock_dir(): string
{
    $dir = getenv('OPS_LOCK_DIR');
    if ($dir === false || $dir === '') {
        $dir = '/var/www/html/storage/ops-locks';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail(EXIT_CONFLICT, "cannot create lock dir {$dir}");
    }
    return $dir;
}

/** Validates the slug (exit 2 on bad input) and returns the full lock path. */
function lock_path(string $slug): string
{
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $slug)) {
        fail(EXIT_USAGE, "invalid slug '{$slug}' (allowed: [A-Za-z0-9._-]+)");
    }
    return lock_dir() . '/' . $slug . '.lock';
}

function read_lock(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/** Malformed expires_at counts as expired: TTL must guarantee orphan progress. */
function is_expired(array $lock): bool
{
    $exp = $lock['expires_at_utc'] ?? null;
    if (!is_string($exp) || strtotime($exp) === false) {
        return true;
    }
    return strtotime($exp) <= time();
}

function remaining_seconds(array $lock): int
{
    $exp = $lock['expires_at_utc'] ?? null;
    if (!is_string($exp) || strtotime($exp) === false) {
        return -1;
    }
    return strtotime($exp) - time();
}

function cmd_acquire(string $slug, int $ttlMinutes): void
{
    $path = lock_path($slug);
    $now = time();
    $lock = [
        'claimed_by' => get_current_user() . '@' . gethostname(),
        'pid' => getmypid(),
        'acquired_at_utc' => gmdate('c', $now),
        'expires_at_utc' => gmdate('c', $now + $ttlMinutes * 60),
    ];
    $fh = @fopen($path, 'x'); // O_EXCL
    if ($fh === false) {
        $existing = read_lock($path);
        if ($existing !== null && !is_expired($existing)) {
            fwrite(STDERR, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            exit(EXIT_CONFLICT); // FAIL CLOSED
        }
        $holder = $existing['claimed_by'] ?? 'unknown';
        @unlink($path);
        $fh = @fopen($path, 'x');
        if ($fh === false) {
            fail(EXIT_CONFLICT, "lock '{$slug}' re-contended after expired-lock replace");
        }
        echo "replaced expired lock held by {$holder}\n";
    }
    fwrite($fh, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fclose($fh);
    @chmod($path, 0664);
    echo $path . "\n";
}

function cmd_release(string $slug, string $claimedBy): void
{
    $path = lock_path($slug);
    $lock = read_lock($path);
    if ($lock === null) {
        fail(EXIT_CONFLICT, "no lock for '{$slug}'");
    }
    if (($lock['claimed_by'] ?? null) !== $claimedBy) {
        $holder = $lock['claimed_by'] ?? 'unknown';
        fail(EXIT_CONFLICT, "refused: claimed_by mismatch for '{$slug}' (holder: {$holder})");
    }
    if (!@unlink($path)) {
        fail(EXIT_CONFLICT, "failed to unlink {$path}");
    }
    echo "released {$path}\n";
}

function cmd_status(?string $slug): void
{
    $dir = lock_dir();
    if ($slug !== null) {
        $path = lock_path($slug); // also validates
        $files = is_file($path) ? [$slug . '.lock'] : [];
    } else {
        $files = array_map('basename', glob($dir . '/*.lock') ?: []);
    }
    sort($files);
    $out = [];
    foreach ($files as $file) {
        $lock = read_lock($dir . '/' . $file);
        if ($lock === null) {
            continue;
        }
        $lock['slug'] = substr($file, 0, -strlen('.lock'));
        $lock['remaining_seconds'] = remaining_seconds($lock);
        $out[] = $lock;
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function cmd_sweep(): void
{
    $dir = lock_dir();
    $removed = 0;
    foreach (glob($dir . '/*.lock') ?: [] as $path) {
        $lock = read_lock($path);
        if ($lock !== null && is_expired($lock) && @unlink($path)) {
            $removed++;
        }
    }
    echo $removed . "\n";
}

function main(array $argv): void
{
    $cmd = $argv[1] ?? null;
    switch ($cmd) {
        case 'acquire':
            if (!isset($argv[2])) {
                fail(EXIT_USAGE, 'usage: ops_lock.php acquire <slug> [ttl_minutes=120]');
            }
            $ttl = isset($argv[3]) ? filter_var($argv[3], FILTER_VALIDATE_INT) : 120;
            if ($ttl === false || $ttl < 1) {
                fail(EXIT_USAGE, 'ttl_minutes must be a positive integer');
            }
            cmd_acquire($argv[2], $ttl);
            break;
        case 'release':
            if (!isset($argv[3])) {
                fail(EXIT_USAGE, 'usage: ops_lock.php release <slug> <claimed_by>');
            }
            cmd_release($argv[2], $argv[3]);
            break;
        case 'status':
            cmd_status($argv[2] ?? null);
            break;
        case 'sweep':
            cmd_sweep();
            break;
        default:
            fail(EXIT_USAGE, "unknown command '{$cmd}' — usage: ops_lock.php {acquire <slug> [ttl=120] | release <slug> <claimed_by> | status [slug] | sweep}");
    }
    exit(EXIT_OK);
}

main($argv);
