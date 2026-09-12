<?php

// H4516 — student-public domain (split from routes/web.php; lines 360-378).
// Registration order across routes/web/*.php is load-bearing — see routes/web.php.
// Public SRS surfaces — kept separate from the auth'd student.php
// block: both live in the student domain but different guard context.

use App\Http\Controllers\SrsController;
use Illuminate\Support\Facades\Route;

// Public deck trial (shareable per-deck URLs, no auth). Canonical path: /koloda.
// Must sit BEFORE the promo catch-all /{slug}. Same srs.enabled gate as cabinet.
if (config('srs.enabled')) {
    Route::get('/koloda', [SrsController::class, 'publicIndex'])->name('srs.index');

    // Язык словом в пути вместо ?lang= (H3093-паттерн). ДО /koloda/{slug} —
    // иначе implicit slug съел бы /koloda/yazyk как имя колоды.
    Route::get('/koloda/yazyk/{word}', [SrsController::class, 'publicIndex'])
        ->where('word', 'sanskrit|hindi|vse')
        ->name('srs.index.lang');

    Route::get('/koloda/{slug}', [SrsController::class, 'publicReview'])->name('srs.deck');

    // Legacy /srs → /koloda (301). Keep old Telegram/blog links working.
    Route::permanentRedirect('/srs', '/koloda');
    Route::get('/srs/{slug}', function (string $slug) {
        return redirect('/koloda/'.$slug, 301);
    })->where('slug', '[A-Za-z0-9\-]+');
}
