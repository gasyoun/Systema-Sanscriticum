<?php

use App\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Двухфакторная защита админ-ролей (H6352-r5)
|--------------------------------------------------------------------------
|
| Маршруты доступны только аутентифицированному пользователю; гейт
| App\Http\Middleware\RequireTwoFactor пропускает их, чтобы не запирать
| человека в редиректе на самого себя.
|
*/

Route::middleware('auth')->group(function () {
    Route::get('/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('/two-factor/verify', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('two-factor.verify');
    Route::post('/two-factor/logout', [TwoFactorController::class, 'logout'])->name('two-factor.logout');
});
