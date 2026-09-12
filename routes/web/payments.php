<?php

// H4516 — payments domain (split from routes/web.php; lines 1028-1097).
// Registration order across routes/web/*.php is load-bearing — see routes/web.php.
use App\Http\Controllers\BankClaimController;
use App\Http\Controllers\CompanyInvoiceController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaypalClaimController;
use App\Http\Controllers\TrialController;
use App\Models\Payment;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// --- РОУТЫ ДЛЯ ТОЧКА БАНКА ---
// Перенес их выше роута-перехватчика {slug} для безопасности
// throttle:5,1 — как у deposit/trial: публичный приём email + создание платежа,
// защита от ботов (спам pending-платежей, enumeration email, злоупотребление API Точки).
Route::post('/payment/create', [PaymentController::class, 'createPayment'])
    ->middleware('throttle:5,1')
    ->name('payment.create');
Route::get('/payment/success', [PaymentController::class, 'success'])->name('payment.success');
Route::get('/payment/fail', [PaymentController::class, 'fail'])->name('payment.fail');

// Депозит (бронь курса) — отдельный POST, тот же эквайринг.
// Биндинг по slug — симметрично с /shop/course/{course:slug}.
// ВАЖНО: строго до catch-all /{slug} ниже.
// throttle:5,1 — публичный эндпоинт, защита от ботов, которые иначе могли бы
// насоздавать pending-платежей на чужие email со скоростью сети.
Route::post('/deposit/{course:slug}', [DepositController::class, 'create'])
    ->middleware('throttle:5,1')
    ->name('deposit.create');

// Пробное занятие — отдельный POST, тот же эквайринг. Строго до catch-all /{slug}.
Route::post('/trial/{course:slug}', [TrialController::class, 'create'])
    ->middleware('throttle:5,1')
    ->name('trial.create');

// Оплата из-за рубежа (PayPal): форма-заявка студента + приём. Автосписания нет —
// платёж ложится pending и сверяется вручную в админке. Строго до catch-all /{slug}.
// throttle:5,1 — публичный приём email + создание pending-платежа (защита от ботов).
Route::get('/paypal/{tariff}', [PaypalClaimController::class, 'show'])
    ->name('paypal.claim.show');
// H3990: режим доплаты (разовая акция, без нового тарифа) — та же форма с
// фиксированной €22/$26 и проводкой «закрыть открытый счёт-доплату 2 000 ₽».
Route::get('/paypal/{tariff}/doplata', [PaypalClaimController::class, 'showSupplement'])
    ->name('paypal.claim.supplement');
Route::post('/paypal/{tariff}', [PaypalClaimController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('paypal.claim.store');

// Оплата банковским переводом (SEPA/SWIFT на внешний счёт получателя школы,
// H3497): зеркало PayPal-заявки. Флаг BANK_CLAIM_ENABLED default OFF (404).
// Строго до catch-all /{slug}; throttle:5,1 — защита от спама pending-платежей.
Route::get('/bank/{tariff}', [BankClaimController::class, 'show'])
    ->name('bank.claim.show');
Route::post('/bank/{tariff}', [BankClaimController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('bank.claim.store');

// Счёт для компании / ИП (безнал). Flag COMPANY_INVOICE_ENABLED; pending until
// admin confirms bank transfer. Print path BEFORE /invoice/{tariff} so "print"
// is never resolved as a Tariff id. Strictly before catch-all /{slug}.
Route::get('/invoices/{payment}/print', [CompanyInvoiceController::class, 'print'])
    ->middleware('auth')
    ->name('invoice.print');
Route::get('/invoice/{tariff}', [CompanyInvoiceController::class, 'show'])
    ->name('invoice.claim.show');
Route::post('/invoice/{tariff}', [CompanyInvoiceController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('invoice.claim.store');

// Приватный чек PayPal-заявки: только персонал (сверка платежа в админке).
// Диск 'local' (не public) — скрин может содержать личные/платёжные данные.
Route::get('/admin/payments/{payment}/paypal-proof', function (Payment $payment) {
    $u = auth()->user();
    abort_unless($u && $u->is_admin, 403);
    abort_unless(
        filled($payment->proof_path) && Storage::disk('local')->exists($payment->proof_path),
        404
    );

    return Storage::disk('local')->download($payment->proof_path);
})->middleware('auth')->name('paypal.proof');
