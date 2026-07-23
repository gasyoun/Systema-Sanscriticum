<?php

declare(strict_types=1);

// Prefer this worktree's app/ over a junctioned vendor's classmap (other worktrees/main).
require __DIR__.'/../vendor/autoload.php';

$appRoot = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($appRoot): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, 4)).'.php';
    $file = $appRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (is_file($file)) {
        require $file;
    }
}, true, true);
