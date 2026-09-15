<?php

// H4516 — admin domain (split from routes/web.php; lines 1099-1181).
// Registration order across routes/web/*.php is load-bearing — see routes/web.php.
use App\Http\Controllers\AccountantGuideShotController;
use App\Http\Controllers\Editor\LectureDraftController;
use App\Models\Course;
use App\Models\CourseDesignAsset;
use App\Models\MarathonEnrollment;
use App\Services\Design\CourseDesignArchiver;
use App\Support\RoleGate;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

// --- РЕДАКТОР ЛЕКЦИЙ (Filament-панель /editor) ---
Route::middleware(['web', 'auth'])
    ->prefix('editor/lectures/{draft}')
    ->name('editor.lecture.')
    ->group(function () {
        Route::get('preview', [LectureDraftController::class, 'preview'])
            ->name('preview');
        Route::get('asset/{path}', [LectureDraftController::class, 'asset'])
            ->where('path', '.*')
            ->name('asset');
        Route::post('patch', [LectureDraftController::class, 'patch'])
            ->name('patch');
    });
