<?php

use App\Http\Controllers\PromoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

require __DIR__.'/web/01-checkout-and-storefront.php';
require __DIR__.'/web/02-auth-and-public-pages.php';
require __DIR__.'/web/03-student-cabinet.php';
require __DIR__.'/web/04-technical-and-marketing.php';
require __DIR__.'/web/05-payments-and-money.php';
require __DIR__.'/web/06-editor-and-misc-public.php';

Route::get('/{slug}', [PromoController::class, 'show'])->name('promo.show');
