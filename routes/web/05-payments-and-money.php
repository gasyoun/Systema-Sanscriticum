<?php

use App\Http\Controllers\AccountantGuideShotController;
use App\Http\Controllers\BankClaimController;
use App\Http\Controllers\CompanyInvoiceController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaypalClaimController;
use App\Http\Controllers\PlacementQuizController;
use App\Http\Controllers\TeacherPayController;
use App\Http\Controllers\TrialController;
use App\Models\Course;
use App\Models\CourseDesignAsset;
use App\Models\Lesson;
use App\Models\MarathonEnrollment;
use App\Models\Payment;
use App\Services\Design\CourseDesignArchiver;
use App\Services\Materials\TranscriptArchiver;
use App\Support\RoleGate;
use App\Support\Roles;
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

// H4818 (R2609-01): F2 rung-placement квиз — отдельный шаг ДО формы пробного.
// Оба маршрута 404, пока features.f2_placement_quiz выключен (default).
// Пишет только в сессию — Deal/Payment не трогает. Строго до catch-all /{slug}.
Route::get('/rung-placement', [PlacementQuizController::class, 'show'])
    ->name('placement.quiz.show');
Route::post('/rung-placement', [PlacementQuizController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('placement.quiz.store');

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

// H445 Phase 4 (H546) — Day-2 mantra-reading voice note (marathon).
// Диск 'local' (не public) — приватная запись голоса, не публичный медиафайл.
Route::get('/admin/marathon/mantra-voice/{enrollment}', function (MarathonEnrollment $enrollment) {
    abort_unless(RoleGate::adminOnly(), 403);
    abort_unless(
        filled($enrollment->day2_voice_path) && Storage::disk((string) $enrollment->day2_voice_disk)->exists($enrollment->day2_voice_path),
        404
    );

    return Storage::disk((string) $enrollment->day2_voice_disk)->download($enrollment->day2_voice_path);
})->middleware('auth')->name('admin.marathon.mantra-voice.download');

// Исходник PSD дизайнерского баннера: приватный диск, отдаём только персоналу.
// Свой маршрут, а НЕ существующий /force-download: тот пускает по legacy-флагу
// is_admin (синхронен с super_admin/admin), и manager — для которого страница
// «Дизайн курсов» и делалась — через него не прошёл бы.
Route::get('/admin/course-design/{asset}/psd', function (CourseDesignAsset $asset) {
    abort_unless(RoleGate::any(Roles::ADMIN, Roles::MANAGER), 403);
    abort_unless(
        filled($asset->psd_path) && Storage::disk((string) $asset->psd_disk)->exists($asset->psd_path),
        404
    );

    return Storage::disk((string) $asset->psd_disk)
        ->download($asset->psd_path, $asset->psd_original_name ?: basename($asset->psd_path));
})->middleware('auth')->name('course-design.psd');

// ZIP с баннерами курса: маршрут сам собирает архив и тут же его отдаёт, удаляя
// файл после отправки. Собирать в Filament-действии и редиректить сюда нельзя —
// Filament оставляет после такого редиректа пустую модалку действия (поймано
// прокликиванием). Заодно в пути нет ни токена, ни имени файла от пользователя.
Route::get('/admin/course-design/{course}/archive', function (Course $course, CourseDesignArchiver $archiver) {
    abort_unless(RoleGate::any(Roles::ADMIN, Roles::MANAGER), 403);

    try {
        $path = $archiver->build($course);
    } catch (RuntimeException $e) {
        abort(404, $e->getMessage());
    }

    return response()->download($path, $archiver->downloadName($course))->deleteFileAfterSend();
})->middleware('auth')->name('course-design.archive');

// ZIP со стенограммами уроков: внутри папка на курс, файл на урок. Маршрут сам
// собирает архив и удаляет его после отправки — стенограмма это платная лекция
// целиком, на публичном диске ей делать нечего. Гейт тот же, что у страницы
// «Материалы уроков», откуда ведёт кнопка (супер-админ). ?course=ID — один курс.
Route::get('/admin/lesson-materials/transcripts', function (TranscriptArchiver $archiver) {
    abort_unless(RoleGate::isSuperAdmin(), 403);

    $course = filled(request()->query('course'))
        ? Course::query()->findOrFail((int) request()->query('course'))
        : null;

    try {
        $path = $archiver->build($course);
    } catch (RuntimeException $e) {
        abort(404, $e->getMessage());
    }

    return response()->download($path, $archiver->downloadName($course))->deleteFileAfterSend();
})->middleware('auth')->name('lesson-materials.transcripts');

// Стенограмма одного урока для персонала: та же проверка ролей, что у раздела
// «Уроки» (админ/преподаватель). Студентам файл отдаёт GatedAssetController по
// доступу к курсу — этот маршрут его не заменяет и в кабинете не используется.
Route::get('/admin/lessons/{lesson}/transcript', function (Lesson $lesson) {
    abort_unless(RoleGate::any(Roles::ADMIN, Roles::TEACHER), 403);

    $path = (string) $lesson->transcript_file;
    // Абсолютный URL опубликованной лекции — не наш файл (как в GatedAssetController).
    abort_if($path === '' || preg_match('#^https?://#i', $path) === 1 || str_starts_with($path, '/'), 404);

    foreach (['local', 'public'] as $disk) {
        if (Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->download($path, $lesson->transcriptDownloadName());
        }
    }

    abort(404, 'Файл стенограммы не найден на дисках.');
})->middleware('auth')->name('admin.lesson.transcript');

// Скачивание планировочных шаблонов «Нескучных финансов» (Финмодель, Бюджет,
// План доходов/расходов) — гибридная стратегия H207: живые отчёты в панели +
// эти workbooks вручную. Доступ — админ ИЛИ бухгалтер (+ супер-админ).
// Имена берём из белого списка, чтобы исключить обход каталога.
// H3214 — кадры книги бухгалтера из storage (не git). Basename only.
Route::get('/staff/accountant-guide-shots/{file}', [AccountantGuideShotController::class, 'show'])
    ->middleware('auth')
    ->where('file', '[A-Za-z0-9._-]+')
    ->name('accountant-guide.shot');

Route::get('/admin/finance-templates/{name}', function (string $name) {
    abort_unless(RoleGate::finance(), 403);

    $catalog = [
        'finmodel' => ['file' => 'finmodel.xlsx', 'as' => 'НФ — Финансовая модель.xlsx'],
        'budget' => ['file' => 'budget.xlsx', 'as' => 'НФ — Бюджет.xlsx'],
        'plan-income-expense' => ['file' => 'plan-income-expense.xlsx', 'as' => 'НФ — План доходов и расходов.xlsx'],
    ];

    abort_unless(isset($catalog[$name]), 404);

    $path = 'finance-templates/'.$catalog[$name]['file'];
    abort_unless(Storage::disk('local')->exists($path), 404);

    return Storage::disk('local')->download($path, $catalog[$name]['as']);
})->middleware('auth')->name('finance.template');
