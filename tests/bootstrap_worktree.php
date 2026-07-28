<?php

declare(strict_types=1);
use Composer\Autoload\ClassLoader;

/**
 * Worktree-safe autoload: when vendor/ is a Windows junction to the main
 * clone, Composer resolves $baseDir to the main tree and the classmap points
 * every App\* file there. Strip App/Tests/Database classmap entries and
 * re-bind PSR-4 to THIS worktree so new classes/controllers load correctly.
 */
$root = dirname(__DIR__);
$loader = require $root.'/vendor/autoload.php';

if ($loader instanceof ClassLoader) {
    $loader->setPsr4('App\\', [$root.'/app']);
    $loader->setPsr4('Database\\Factories\\', [$root.'/database/factories']);
    $loader->setPsr4('Database\\Seeders\\', [$root.'/database/seeders']);
    $loader->setPsr4('Tests\\', [$root.'/tests']);

    $ref = new ReflectionClass($loader);
    if ($ref->hasProperty('classMap')) {
        $prop = $ref->getProperty('classMap');
        $prop->setAccessible(true);
        /** @var array<string, string> $map */
        $map = $prop->getValue($loader);
        foreach (array_keys($map) as $class) {
            if (
                str_starts_with($class, 'App\\')
                || str_starts_with($class, 'Tests\\')
                || str_starts_with($class, 'Database\\')
            ) {
                unset($map[$class]);
            }
        }
        $prop->setValue($loader, $map);
    }

    if ($ref->hasProperty('classMapAuthoritative')) {
        $auth = $ref->getProperty('classMapAuthoritative');
        $auth->setAccessible(true);
        $auth->setValue($loader, false);
    }
}

return $loader;
