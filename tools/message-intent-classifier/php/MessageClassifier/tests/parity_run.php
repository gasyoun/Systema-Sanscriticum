<?php

declare(strict_types=1);

/**
 * Golden-parity runner: php/MessageClassifier vs vectors/golden.json.
 *
 * Usage: php php/MessageClassifier/tests/parity_run.php
 * Exit 0 = all green. Reads the SAME vectors as the Python suite.
 */

require __DIR__.'/../../../php/vendor/autoload.php';

$repoRoot = dirname(__DIR__, 3);
$goldenPath = $repoRoot.'/vectors/golden.json';
if (! is_file($goldenPath)) {
    fwrite(STDERR, "golden vectors not found: {$goldenPath}\n");
    exit(1);
}
$doc = json_decode((string) file_get_contents($goldenPath), true, 512, JSON_THROW_ON_ERROR);
$vectors = $doc['vectors'] ?? [];
if (count($vectors) < 60) {
    fwrite(STDERR, 'golden vector count '.count($vectors)." < 60\n");
    exit(1);
}

$ids = array_column($vectors, 'id');
foreach (['g-neg-a', 'g-neg-b'] as $required) {
    if (! in_array($required, $ids, true)) {
        fwrite(STDERR, "missing required negation vector {$required}\n");
        exit(1);
    }
}

$loader = new MessageClassifier\Loader($repoRoot);
$classifier = new MessageClassifier\Classifier($loader);

$planes = MessageClassifier\Loader::PLANES;
$failures = [];
foreach ($vectors as $vector) {
    $got = $classifier->classifyAll((string) $vector['text']);
    $expect = $vector['expect'] ?? [];
    foreach ($planes as $plane) {
        $want = $expect[$plane] ?? null;
        $have = $got[$plane];
        if ($want === null) {
            if ($have !== null) {
                $failures[] = sprintf(
                    '%s: plane=%s expected null, got %s (%s)',
                    $vector['id'],
                    $plane,
                    $have['category'],
                    $have['reason']
                );
            }
            continue;
        }
        if ($have === null) {
            $failures[] = sprintf('%s: plane=%s expected %s, got null', $vector['id'], $plane, $want['category']);
        } elseif ($have !== $want) {
            $failures[] = sprintf(
                '%s: plane=%s expected %s (%s), got %s (%s)',
                $vector['id'],
                $plane,
                $want['category'],
                $want['reason'],
                $have['category'],
                $have['reason']
            );
        }
    }
}

$count = count($vectors);
echo "parity_run.php: {$count} golden vectors against PHP engine\n";
if ($failures !== []) {
    echo 'FAILURES: '.count($failures)."\n";
    foreach (array_slice($failures, 0, 20) as $failure) {
        echo ' - '.$failure."\n";
    }
    exit(1);
}
echo "ALL GREEN — byte-identical parity with engine_py over {$count} vectors\n";
