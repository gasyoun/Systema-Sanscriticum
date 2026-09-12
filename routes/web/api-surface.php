<?php

// H4516 — api-surface domain (split from routes/web.php; lines 326-358).
// Registration order across routes/web/*.php is load-bearing — see routes/web.php.
use App\Http\Controllers\Api\GamesSrsOnboardingController;
use App\Http\Controllers\Api\GameTelemetryController;
use App\Http\Controllers\Api\LilaGateController;
use Illuminate\Support\Facades\Route;

// Auth-state probe for the free static games under public/lila/ — lets
// gate.js skip the "register" wall for logged-in students. Public, web-guard
// (session cookie) so it reflects the browser's own login; no CSRF (GET, no
// state change). See public/lila/gate.js.
Route::get('/api/games/auth', fn () => response()->json(['authenticated' => auth()->check()]))
    ->name('games.auth');

// H4396 — серверная половина ворот /lila: бюджет бесплатных раундов живёт в
// game_events (event=round), ключ — производный от web-сессии (не от
// localStorage). GET читает бюджет, POST фиксирует один завершённый раунд;
// оба публичные (как games/auth — web-guard, состояние браузерной сессии),
// POST без CSRF (как games/event — маячок без токена), затроттлён.
Route::get('/api/games/budget', [LilaGateController::class, 'budget'])
    ->middleware('throttle:60,1')
    ->name('games.budget');
Route::post('/api/games/round', [LilaGateController::class, 'round'])
    ->middleware('throttle:60,1')
    ->name('games.round');

// First-party funnel telemetry for the same free games (H1360). Public + web-guard
// so the `authenticated` flag is read server-side from the browser session (the
// client cannot spoof it); anonymous, no PII stored. CSRF-exempt (see
// VerifyCsrfToken) because the beacon carries no token; throttled per IP.
Route::post('/api/games/event', [GameTelemetryController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('games.event');

// H1680 — Wave 2: onboarding-from-games SRS import. Auth-only (it only ever
// reads the CALLER's own game_events, matched by the anon_id they post back);
// throttled like the other games endpoints. No-op while srs.enabled is OFF.
Route::post('/api/games/srs-onboarding-import', [GamesSrsOnboardingController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('games.srs-onboarding-import');
