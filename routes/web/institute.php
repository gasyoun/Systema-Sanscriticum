<?php

// H4516 — institute domain (split from routes/web.php; lines 502-517).
// Registration order across routes/web/*.php is load-bearing — see routes/web.php.
use App\Http\Controllers\InstituteController;
use App\Http\Controllers\InstituteDonateController;
use Illuminate\Support\Facades\Route;

// Институт исследования санскрита — витрина ДПП ПК «Санскрит» 72 ч (заявочная форма).
Route::get('/institut', [InstituteController::class, 'landing'])->name('institute.landing');
Route::post('/institut/zayavka', [InstituteController::class, 'apply'])
    ->middleware('throttle:6,1')
    ->name('institute.apply');

// Меценаты Института — страница добровольных пожертвований (ст. 582 ГК,
// свободная сумма, без встречного пакета благ; реквизиты — config/institute.php)
// + публичный реестр благодарностей меценатам (план института N3).
Route::get('/mecenaty', [InstituteDonateController::class, 'page'])->name('institute.mecenaty');

// Онлайн-приём пожертвований через Точку (план института N2). Контроллер сам
// 404-ит при institute.donations_enabled=false — тёмный деплой безопасен.
Route::post('/mecenaty/donate', [InstituteDonateController::class, 'donate'])
    ->middleware('throttle:6,1')
    ->name('institute.donate');
