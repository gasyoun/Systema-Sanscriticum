<?php

declare(strict_types=1);

/**
 * Windows-only: strip the MadelineProto polyfill stdout WARNING so Livewire/Filament
 * AJAX JSON is not corrupted by a vendor echo on every request.
 *
 * Hooked from composer.json post-autoload-dump. Idempotent. No-op on non-Windows
 * and when vendor is absent. Safe to re-run after every composer install/update.
 *
 * Context: docs/OPTIMISATION_BACKLOG_2026H2.md §2 · H2014 · .ai_state.md Dev Notes
 * (01-07-2026 MadelineProto polyfill breaks all Livewire locally on Windows).
 */

if (PHP_OS_FAMILY !== 'Windows') {
    exit(0);
}

$path = dirname(__DIR__).DIRECTORY_SEPARATOR
    .'vendor'.DIRECTORY_SEPARATOR
    .'danog'.DIRECTORY_SEPARATOR
    .'madelineproto'.DIRECTORY_SEPARATOR
    .'src'.DIRECTORY_SEPARATOR
    .'polyfill.php';

if (! is_file($path)) {
    exit(0);
}

$src = file_get_contents($path);
if ($src === false) {
    fwrite(STDERR, "silence_madeline_windows_polyfill: cannot read {$path}\n");
    exit(0);
}

if (str_contains($src, 'H2014_SILENCED_WINDOWS_WARNING')) {
    exit(0);
}

// Match the known WARNING echo block (danog/MadelineProto polyfill).
$pattern = '/if\s*\(\s*\\\\?PHP_OS_FAMILY\s*===\s*[\'"]Windows[\'"]\s*\)\s*\{\s*echo\s+["\']WARNING: MadelineProto runs around 10x slower[^;]+;\s*\}/s';

$replacement = "if (\\PHP_OS_FAMILY === 'Windows') {\n"
    ."    // H2014_SILENCED_WINDOWS_WARNING — do not echo: corrupts Livewire JSON on Windows.\n"
    ."    // Original vendor warning omitted by scripts/silence_madeline_windows_polyfill.php\n"
    .'}';

$patched = preg_replace($pattern, $replacement, $src, 1, $count);
if ($count !== 1 || ! is_string($patched)) {
    // Vendor text drifted — leave file alone; agents should re-check polyfill.php.
    exit(0);
}

if (file_put_contents($path, $patched) === false) {
    fwrite(STDERR, "silence_madeline_windows_polyfill: cannot write {$path}\n");
    exit(0);
}

fwrite(STDOUT, "silence_madeline_windows_polyfill: silenced Windows WARNING in polyfill.php\n");
