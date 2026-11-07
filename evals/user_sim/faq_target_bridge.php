<?php

/**
 * H6851 — integration recipe artifact: stdin/stdout JSONL bridge from the
 * user-simulator harness to the support lane's REAL offline retrieval surface.
 *
 * STATUS: NOT RUN in H6851 — this worktree has no vendor/ and no .env
 * (PILOT_PROTOCOL.md parks the live pilot as a named residual). Ship as-is as
 * the recipe; execute only after `composer install` + `knowledge:index`.
 *
 * Protocol: one JSON object per line on STDIN
 *   {"text": "Как оплатить курс поблочно?"}
 * one JSON object per line on STDOUT
 *   {"retrieved_chunk_ids": ["политика-и-поддержка/оплата-блоки-поблочно-vs-целиком"], "refusal": false}
 *
 * Reuse, not duplication (H3234/H4001): scoring/retrieval is the same
 * Bm25FaqRetriever the lane serves — eval parity with knowledge:eval.
 *
 * Usage:
 *   php evals/user_sim/faq_target_bridge.php <<'EOF'
 *   {"text": "Где смотреть запись занятия если пропустил урок?"}
 *   EOF
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** @var App\Services\Support\Faq\Bm25FaqRetriever $retriever */
$retriever = $app->make(App\Services\Support\Faq\Bm25FaqRetriever::class);

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $req = json_decode($line, true);
    $text = is_array($req) ? (string) ($req['text'] ?? '') : '';
    if ($text === '') {
        fwrite(STDOUT, json_encode(['error' => 'empty text'], JSON_UNESCAPED_UNICODE) . "\n");
        continue;
    }
    $hits = $retriever->retrieve($text, 5); // top-k mirrors knowledge:eval default
    $chunkIds = [];
    foreach ($hits as $hit) {
        $chunkIds[] = $hit->chunk_id ?? (string) ($hit['chunk_id'] ?? '');
    }
    fwrite(STDOUT, json_encode(
        ['retrieved_chunk_ids' => array_values(array_filter($chunkIds)), 'refusal' => $chunkIds === []],
        JSON_UNESCAPED_UNICODE
    ) . "\n");
}
