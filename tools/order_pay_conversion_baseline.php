<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Thin CLI wrapper for NOBORING Wave 0 baseline (roadmap names this path).
 *
 * Prefer: php artisan dozhim:baseline
 * This script boots Laravel and runs the same artisan command.
 *
 * Usage (from repo root):
 *   php tools/order_pay_conversion_baseline.php
 *   php tools/order_pay_conversion_baseline.php --days=90
 *   php tools/order_pay_conversion_baseline.php --json
 */
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$argv = $_SERVER['argv'] ?? ['order_pay_conversion_baseline.php'];
$args = array_slice($argv, 1);

$options = [];
$days = [];
$n = count($args);
for ($i = 0; $i < $n; $i++) {
    $a = $args[$i];
    if ($a === '--json') {
        $options['json'] = true;

        continue;
    }
    if (str_starts_with($a, '--days=')) {
        $days[] = (int) substr($a, 7);

        continue;
    }
    if ($a === '--days' && isset($args[$i + 1])) {
        $days[] = (int) $args[$i + 1];
        $i++;
    }
}
if ($days !== []) {
    $options['days'] = $days;
}

$status = $kernel->call('dozhim:baseline', $options);
exit($status);
