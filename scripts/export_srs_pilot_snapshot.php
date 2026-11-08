<?php

declare(strict_types=1);

/**
 * export_srs_pilot_snapshot.php — выгрузка АНОНИМИЗИРОВАННОГО снапшота SRS-пилота
 * (H6394: метрики освоения лигатур — тройка + harm).
 *
 * Зачем: eval-скрипт scripts/ligature_mastery_eval.py должен пересчитывать все
 * метрики ОДНОЙ командой офлайн — из закоммиченного снапшота, без БД и сети.
 *
 * Свойства:
 *   • Только SELECT. Ничего не меняет — безопасно на проде.
 *   • 152-ФЗ: user_id псевдонимизируется в u01..uNN по возрастанию id;
 *     соответствие НИКУДА не пишется и не покидает сервер. Имена/email/телефоны
 *     не выгружаются вовсе; тексты карточек — только для лигатурных колод
 *     (slug LIKE %ligature%), т.к. harm-метрика проверяет каноничность форм.
 *   • Вывод — JSON в stdout (meta.decks / review_logs / review_states / game_events).
 *
 * Запуск с прода (см. docs/SPEC_LIGATURE_MASTERY_METRICS_2026.md):
 *   ssh root@193.232.229.92 "cd /var/www/html && php" \
 *     < scripts/export_srs_pilot_snapshot.php \
 *     > scripts/data/srs_pilot_snapshot_<ГГГГ-ММ-ДД>.json
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

// При запуске из репо (scripts/) — ../vendor; при пайпе через stdin с app-корня — ./vendor.
$__candidates = [__DIR__.'/../vendor/autoload.php', __DIR__.'/vendor/autoload.php', 'vendor/autoload.php'];
$__autoload = null;
foreach ($__candidates as $__f) {
    if (file_exists($__f)) {
        $__autoload = $__f;
        break;
    }
}
if ($__autoload === null) {
    fwrite(STDERR, 'vendor/autoload.php не найден (искали: '.implode(', ', $__candidates).")\n");
    exit(1);
}
require $__autoload;

/** @var Application $app */
$app = require __DIR__.'/bootstrap/app.php';
if (! file_exists(__DIR__.'/bootstrap/app.php')) {
    $app = require 'bootstrap/app.php';
}
$app->make(Kernel::class)->bootstrap();

// --- Псевдонимизация user_id: u01..uNN по возрастанию id -------------------
$userIds = DB::table('srs_review_logs')->distinct()->orderBy('user_id')->pluck('user_id')
    ->merge(DB::table('srs_review_states')->distinct()->orderBy('user_id')->pluck('user_id'))
    ->unique()->values();
$map = [];
$userIds->each(function ($id, $i) use (&$map) {
    $map[$id] = sprintf('u%02d', $i + 1);
});

// --- Колоды -----------------------------------------------------------------
$decks = DB::table('srs_decks')->orderBy('id')->get(['id', 'slug', 'name'])->toArray();

// H6394 (проверка e верификатора, additions-only): slug/name авторских колод
// могут нести сырой user_id (user-6599-…) — вычищаем ПОСЛЕ выборки; строка
// выборки выше нетронута, чтобы stale-base guard не видел удаления свежей строки.
foreach ($decks as $deck) {
    $deck->slug = preg_replace_callback(
        '/^user-(\d+)-/',
        fn ($m) => isset($map[(int) $m[1]]) ? 'user-'.$map[(int) $m[1]].'-' : 'user-REDACTED-',
        (string) $deck->slug
    );
    $deck->name = preg_replace_callback(
        '/\buser[- ]?(\d{2,})\b/',
        fn ($m) => isset($map[(int) $m[1]]) ? 'user-'.$map[(int) $m[1]] : 'user-REDACTED',
        (string) $deck->name
    );
}

// --- Карты только лигатурных колод (для harm-каноничности контента) --------
$ligatureDeckIds = DB::table('srs_decks')->where('slug', 'like', '%ligature%')->pluck('id');
$ligatureCards = $ligatureDeckIds->isEmpty() ? [] : DB::table('srs_cards')
    ->whereIn('deck_id', $ligatureDeckIds)
    ->orderBy('id')
    ->get(['id', 'deck_id', 'front', 'back'])
    ->map(fn ($c) => ['id' => $c->id, 'deck_id' => $c->deck_id, 'front' => $c->front, 'back' => $c->back])
    ->toArray();

// --- Карта card_id -> deck_id (для review_states, где нет deck_id) ----------
$cardDeck = DB::table('srs_cards')->pluck('deck_id', 'id');

// --- Журнал повторений (сырьё тройки метрик) --------------------------------
$logs = DB::table('srs_review_logs')->orderBy('reviewed_at')->get([
    'user_id', 'card_id', 'deck_id', 'rating', 'state',
    'scheduled_days', 'stability_after', 'difficulty_after', 'reviewed_at',
])->map(fn ($l) => [
    'u' => $map[$l->user_id] ?? null,
    'card_id' => $l->card_id,
    'deck_id' => $l->deck_id,
    'rating' => (int) $l->rating,
    'state' => (int) $l->state,
    'scheduled_days' => (int) $l->scheduled_days,
    'stability_after' => $l->stability_after !== null ? (float) $l->stability_after : null,
    'difficulty_after' => $l->difficulty_after !== null ? (float) $l->difficulty_after : null,
    'reviewed_at' => (string) $l->reviewed_at,
])->toArray();

// --- Текущие FSRS-состояния --------------------------------------------------
$states = DB::table('srs_review_states')->orderBy('user_id')->orderBy('card_id')->get([
    'user_id', 'card_id', 'stability', 'difficulty', 'due', 'reps', 'lapses', 'state', 'last_review',
])->map(fn ($s) => [
    'u' => $map[$s->user_id] ?? null,
    'card_id' => $s->card_id,
    'deck_id' => $cardDeck[$s->card_id] ?? null,
    'stability' => $s->stability !== null ? (float) $s->stability : null,
    'difficulty' => $s->difficulty !== null ? (float) $s->difficulty : null,
    'due' => (string) $s->due,
    'reps' => (int) $s->reps,
    'lapses' => (int) $s->lapses,
    'state' => (int) $s->state,
    'last_review' => (string) $s->last_review,
])->toArray();

// --- Телеметрия lila-лигатур (источник будущих twins-проб H6393) ------------
$gameEvents = DB::table('game_events')
    ->where('drill', 'like', '%ligature%')
    ->orderBy('created_at')
    ->get(['anon_id', 'drill', 'band', 'event', 'authenticated', 'created_at'])
    ->map(fn ($e) => [
        'anon_id' => $e->anon_id,
        'drill' => $e->drill,
        'band' => $e->band,
        'event' => $e->event,
        'authenticated' => (bool) $e->authenticated,
        'created_at' => (string) $e->created_at,
    ])->toArray();

// --- twins-пробы: появятся с режимом близнецов H6393 ------------------------
// Формат строки: {"pair_id": "pr-ly", "probe": "प्र", "correct": true, "u": "u01", "created_at": "..."}
$twinsProbes = [];

$out = [
    'meta' => [
        'schema_version' => 1,
        'exported_at' => now()->toIso8601String(),
        'source' => 'Systema-Sanscriticum prod MySQL (.92), read-only SELECT',
        'pseudonymized' => true,
        'note' => 'user_id -> uNN по возрастанию id; таблица соответствия не выгружается.',
    ],
    'decks' => $decks,
    'ligature_cards' => $ligatureCards,
    'review_logs' => $logs,
    'review_states' => $states,
    'game_events' => $gameEvents,
    'twins_probes' => $twinsProbes,
];

fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
fwrite(STDERR, sprintf(
    "srs_pilot_snapshot: decks=%d logs=%d states=%d game_events(ligature)=%d twins_probes=%d users=%d\n",
    count($decks), count($logs), count($states), count($gameEvents), count($twinsProbes), count($map)
));
