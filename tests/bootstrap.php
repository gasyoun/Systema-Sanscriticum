<?php

declare(strict_types=1);

/**
 * H1488 — worktree-safe PHPUnit bootstrap.
 *
 * This worktree uses a junctioned vendor/ from the main clone. Composer records
 * the main-tree install path, so without an explicit APP_BASE_PATH Laravel would
 * boot against the main checkout (wrong public/, views/, tests/). Pin the base
 * path to this worktree before autoload/boot.
 */
$root = dirname(__DIR__);
$_ENV['APP_BASE_PATH'] = $root;
$_SERVER['APP_BASE_PATH'] = $root;
putenv('APP_BASE_PATH=' . $root);

require $root . '/vendor/autoload.php';