<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| H4516 — per-domain split. Each file below is a verbatim slice of the
| former single web.php; they are required in the ORIGINAL registration
| order, which is load-bearing: most blocks must register before the
| promo catch-all /{slug} (registered last, in web/public.php).
| Do not reorder files without re-running the route:list parity check.
|
*/

use App\Http\Controllers\TeacherPayController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/web/shop.php';
require __DIR__.'/web/api-surface.php';
require __DIR__.'/web/student-public.php';
require __DIR__.'/web/auth.php';
require __DIR__.'/web/content.php';
require __DIR__.'/web/institute.php';
require __DIR__.'/web/student.php';
require __DIR__.'/web/staff.php';
require __DIR__.'/web/support.php';
require __DIR__.'/web/payments.php';

// TODO(H4860): these two routes belong in web/payments.php, right after
// bank.claim.store (their slot in the former single web.php). They are parked
// here because web/payments.php is a money-contour file whose edits need a
// human confirm; a human moves them verbatim. No URI in web/payments.php
// overlaps /teacher-pay/{tariff}, so matching is unchanged either way.

// «Я заплатил преподавателю напрямую» (H4627): анкета-зеркало PayPal-pending.
// Платёж ложится pending с received_account=teacher + received_by_teacher_id;
// куратор сверяет по выписке преподавателя и подтверждает в Filament —
// номинал вычтется из гонорара сам (H4597). Флаг TEACHER_PAY_ENABLED default
// OFF (404). Строго до catch-all /{slug}; throttle:5,1 — защита от спама.
Route::get('/teacher-pay/{tariff}', [TeacherPayController::class, 'show'])
    ->name('teacherpay.claim.show');
Route::post('/teacher-pay/{tariff}', [TeacherPayController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('teacherpay.claim.store');

require __DIR__.'/web/admin.php';
require __DIR__.'/web/public.php';
