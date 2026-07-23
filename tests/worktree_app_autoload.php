<?php

// Worktree App\ autoload prepend when vendor/ is junctioned to another tree.
$appRoot = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($appRoot): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, 4)).'.php';
    $file = $appRoot.'/app/'.$rel;
    if (is_file($file)) {
        require $file;
    }
}, true, true);
