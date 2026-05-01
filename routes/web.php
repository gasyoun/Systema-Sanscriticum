<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Models\Lead;
use App\Models\LandingPage; 
use App\Http\Controllers\StudentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\LeadController; 
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Страница оформления заказа (Checkout)
Route::get('/checkout/{tariff}', [CheckoutController::class, 'show'])->name('checkout.show');

// --- НОВЫЕ РОУТЫ ДЛЯ ПРОМОКОДОВ ---
Route::post('/checkout/{tariff}/promo', [CheckoutController::class, 'applyPromo'])->name('checkout.promo');
Route::post('/checkout/{tariff}/promo/remove', [CheckoutController::class, 'removePromo'])->name('checkout.promo.remove');

// 1. РЕДИРЕКТ (чтобы старые ссылки работали)
Route::get('/promo/{slug}', function ($slug) {
    return redirect('/' . $slug, 301);
});

// --- ГЛАВНАЯ И АВТОРИЗАЦИЯ ---

// --- ИЗМЕНЕННЫЙ РОУТ ГЛАВНОЙ СТРАНИЦЫ (ВИТРИНА) ---
Route::get('/', function () {
    // Берем только опубликованные курсы, по 9 на страницу
    $landings = LandingPage::where('is_active', true)->paginate(9);
    return view('main', compact('landings'));
});

// Витрина магазина курсов
Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');

// Страница одного курса
Route::get('/shop/course/{course:slug}', [ShopController::class, 'show'])->name('shop.course.show');

Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::post('/shop/login', [AuthController::class, 'shopLogin'])
    ->middleware('throttle:5,1')
    ->name('shop.login');

Route::post('/shop/logout', [AuthController::class, 'shopLogout'])
    ->name('shop.logout');
    
// ═══════════════════════════════════════════════════════════════
// СТАТЬИ (блог) — ВАЖНО: должно быть до catch-all /{slug}
// ═══════════════════════════════════════════════════════════════
Route::prefix('s')->name('articles.')->group(function () {
    Route::get('/', [\App\Http\Controllers\ArticleController::class, 'index'])
        ->name('index');

    Route::get('/{article:slug}', [\App\Http\Controllers\ArticleController::class, 'show'])
        ->name('show');
});

// --- ЛИЧНЫЙ КАБИНЕТ СТУДЕНТА (ЗАЩИЩЕНО) ---
Route::middleware(['auth', 'track.activity'])->group(function () {
    
    Route::get('/home', function () {
        $user = auth()->user();
        if ($user->is_admin) {
            return redirect('/admin');
        }
        return redirect()->route('student.dashboard');
    })->name('home');

    Route::get('/calendar', [StudentController::class, 'calendar'])->name('student.calendar');
    Route::get('/cabinet', [StudentController::class, 'dashboard'])->name('student.dashboard');

    Route::get('/open-lessons', [StudentController::class, 'openLessons'])->name('student.open-lessons');

    Route::get('/messages', [StudentController::class, 'messages'])->name('student.messages');
    
    Route::get('/course/{slug}', [StudentController::class, 'showCourse'])->name('student.course');
    Route::get('/course/{slug}/lesson/{lessonId}', [StudentController::class, 'showLesson'])->name('student.lesson');

    Route::post('/course/{slug}/lesson/{lessonId}/complete', [StudentController::class, 'completeLesson'])
        ->name('student.lesson.complete');
        
    Route::get('/course/{slug}/materials/download', [StudentController::class, 'downloadCourseMaterials'])
    ->name('student.course.materials.download');
    
    Route::post('/course/{slug}/lesson/{lessonId}/note', [StudentController::class, 'saveNote'])
        ->name('student.lesson.note');
        
    Route::post('/api/heartbeat', [\App\Http\Controllers\Api\HeartbeatController::class, 'store'])
        ->name('activity.heartbeat');    

    Route::get('/certificate/{id}/download', [StudentController::class, 'downloadCertificate'])
        ->name('student.certificate.download');
        
    Route::get('/admin/leads/export', [LeadController::class, 'export'])
        ->middleware('admin')
        ->name('leads.export');

    Route::get('/telegram/connect', [TelegramController::class, 'connect'])->name('telegram.connect');
});


// --- ТЕХНИЧЕСКИЕ И ДЕБАГ МАРШРУТЫ ---

// БЕЗОПАСНОЕ СКАЧИВАНИЕ ФАЙЛОВ
Route::get('/force-download/{file}', function (string $file) {
    $safeFileName = basename($file);
    $path = $safeFileName; 

    if (!Storage::disk('public')->exists($path)) {
        abort(404, 'Файл не найден.');
    }

    return Storage::disk('public')->download($path);
})->middleware('auth')->name('force-download');

// Debug-маршрут удалён из production (см. BUGS_REPORT.md #1.1)


// --- ОТПРАВКА ФОРМЫ ---
Route::post('/leads/store', [LeadController::class, 'store'])->name('leads.store');
Route::view('/thank-you', 'promo.thankyou')->name('thank.you');


// --- РОУТЫ ДЛЯ ТОЧКА БАНКА ---
// Перенес их выше роута-перехватчика {slug} для безопасности
Route::post('/payment/create', [PaymentController::class, 'createPayment'])->name('payment.create');
Route::get('/payment/success', [PaymentController::class, 'success'])->name('payment.success');
Route::get('/payment/fail', [PaymentController::class, 'fail'])->name('payment.fail');


// --- РЕДАКТОР ЛЕКЦИЙ (Filament-панель /editor) ---
Route::middleware(['web', 'auth'])
    ->prefix('editor/lectures/{draft}')
    ->name('editor.lecture.')
    ->group(function () {
        Route::get('preview', [\App\Http\Controllers\Editor\LectureDraftController::class, 'preview'])
            ->name('preview');
        Route::get('asset/{path}', [\App\Http\Controllers\Editor\LectureDraftController::class, 'asset'])
            ->where('path', '.*')
            ->name('asset');
        Route::post('patch', [\App\Http\Controllers\Editor\LectureDraftController::class, 'patch'])
            ->name('patch');
    });


// --- ЛЕНДИНГИ (БЕЗ ПРЕФИКСА) ---
// ВАЖНО: Этот маршрут ВСЕГДА строго в самом низу!
Route::get('/{slug}', [PromoController::class, 'show'])->name('promo.show');