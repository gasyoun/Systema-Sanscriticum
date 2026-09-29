<?php

declare(strict_types=1);

/**
 * H5274 — regex floor measurement on the frozen classifier corpus.
 *
 * Reads the ClassifierPrecisionTest fixture (tests/fixtures/Support/classifier_corpus_2026_08.json)
 * and scores the LIVE SupportAnswerSuggester rule list against the fixture gold.
 *
 * The rules are extracted from app/Services/Support/SupportAnswerSuggester.php
 * at run time (no copy drift): the `private const RULES = [ ... ];` body is
 * eval'd against a stub of the category constants read from the real model.
 *
 * Usage: php tools/h5274_regex_floor.php [--json]
 */
$root = dirname(__DIR__);
$suggester = $root.'/app/Services/Support/SupportAnswerSuggester.php';
$model = $root.'/app/Models/SupportAnswerSuggestion.php';
$corpus = $root.'/tests/fixtures/Support/classifier_corpus_2026_08.json';

// --- category constant values, read from the real model -------------------
$consts = [];
if (preg_match_all('/const\s+(CATEGORY_[A-Z_]+)\s*=\s*\'([^\']*)\'/', (string) file_get_contents($model), $m, PREG_SET_ORDER)) {
    foreach ($m as $row) {
        $consts[$row[1]] = $row[2];
    }
}

// --- eval the RULES literal against a stub class holding those constants ---
$src = (string) file_get_contents($suggester);
if (! preg_match('/private const RULES = \[(.*?)\n    \];/s', $src, $rm)) {
    fwrite(STDERR, "FAIL: could not locate the RULES constant in {$suggester}\n");
    exit(2);
}

$stub = "class SupportAnswerSuggestion {\n";
foreach ($consts as $name => $value) {
    $stub .= "  const {$name} = ".var_export($value, true).";\n";
}
$stub .= "}\n";

if (! class_exists('SupportAnswerSuggestion', false)) {
    eval($stub);
}
$rules = eval('return ['.$rm[1].' ];');
if (! is_array($rules)) {
    fwrite(STDERR, "FAIL: RULES literal did not eval to an array\n");
    exit(2);
}

// --- score ----------------------------------------------------------------
$data = json_decode((string) file_get_contents($corpus), true, 512, JSON_THROW_ON_ERROR);
$cases = $data['cases'];

$correct = 0;
$errors = [];
$perCat = [];
$total = count($cases);

foreach ($cases as $i => $case) {
    $text = (string) $case['t'];
    $expected = $case['cat'];
    $got = null;
    foreach ($rules as $rule) {
        [$category, $regex] = $rule;
        if (preg_match($regex, $text)) {
            $got = $category;
            break;
        }
    }
    if ($got === $expected) {
        $correct++;
    } else {
        $errors[] = ['idx' => $i + 1, 't' => $text, 'gold' => $expected, 'got' => $got];
    }
    $key = $got ?? 'none';
    $perCat[$key] ??= ['tp' => 0, 'pred' => 0, 'exp' => 0];
    if ($got === $expected) {
        $perCat[$key]['tp']++;
    }
    if ($got !== null) {
        $perCat[$key]['pred']++;
    }
    if ($expected !== null) {
        $perCat[$key]['exp']++;
    }
}

$accuracy = $total > 0 ? $correct / $total : 0.0;
$out = [
    'corpus' => 'tests/fixtures/Support/classifier_corpus_2026_08.json',
    'rules_source' => 'app/Services/Support/SupportAnswerSuggester.php',
    'php' => PHP_VERSION,
    'total' => $total,
    'correct' => $correct,
    'accuracy' => round($accuracy, 4),
    'floor_threshold' => 0.93,
    'floor_met' => $accuracy >= 0.93,
    'errors' => $errors,
    'per_class' => $perCat,
];

if (in_array('--json', $argv, true)) {
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

printf("PHP %s | corpus=%d | correct=%d | accuracy=%.2f%% | floor 93%%: %s\n",
    PHP_VERSION, $total, $correct, 100 * $accuracy, $accuracy >= 0.93 ? 'MET' : 'NOT MET');
foreach ($errors as $e) {
    printf("  #%-3d gold=%-4s got=%-4s %s\n", $e['idx'], $e['gold'] ?? 'null', $e['got'] ?? 'null', mb_substr($e['t'], 0, 70));
}
