<?php

// H4516 — student domain (split from routes/web.php; lines 519-926).
// Registration order across routes/web/*.php is load-bearing — see routes/web.php.
use App\Http\Controllers\Api\CabinetTelemetryController;
use App\Http\Controllers\Api\HeartbeatController;
use App\Http\Controllers\AttendanceNoticeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CabinetMasteryController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\CallbackRequestController;
use App\Http\Controllers\DebtPaymentController;
use App\Http\Controllers\GatedAssetController;
use App\Http\Controllers\GrammarLabController;
use App\Http\Controllers\GrammarLabPilotController;
use App\Http\Controllers\HomeworkController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\PranaShopController;
use App\Http\Controllers\PranaTransferController;
use App\Http\Controllers\ReadingPackController;
use App\Http\Controllers\RecordingGateController;
use App\Http\Controllers\Rq4StudyController;
use App\Http\Controllers\SrsController;
use App\Http\Controllers\Student\AccessSelfServiceController;
use App\Http\Controllers\Student\HindiDictionaryDrillsController;
use App\Http\Controllers\Student\HindiMySrsDeckController;
use App\Http\Controllers\Student\HindiProgrammePlaylistController;
use App\Http\Controllers\Student\HindiTgCuratedPracticeController;
use App\Http\Controllers\Student\HindiTranscriptDrillsController;
use App\Http\Controllers\Student\LessonPackController;
use App\Http\Controllers\StudentAgentController;
use App\Http\Controllers\StudentCabinetGuideController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\TimezoneController;
use App\Http\Controllers\VisualDcsController;
use App\Http\Controllers\VkController;
use App\Models\Course;
use App\Models\MarketingSetting;
use Illuminate\Support\Facades\Route;

// --- СЕКРЕТ-ССЫЛКА ОБХОДА ТЕХОБСЛУЖИВАНИЯ (вне maintenance-группы) ---
Route::get('/maintenance-bypass/{secret}', function (string $secret) {
    $s = MarketingSetting::cached();
    abort_unless(
        $s && filled($s->student_maintenance_secret)
            && hash_equals((string) $s->student_maintenance_secret, $secret),
        404
    );

    return redirect()->route('student.dashboard')
        ->cookie('student_maintenance_bypass', $secret, 60 * 24 * 7); // неделя
})->middleware('auth')->name('maintenance.bypass');

// H4396 — серверные ворота видеопейлоада: страница урока больше не несёт
// сырых unlisted-ID, плеер грузит этот маршрут, контроллер на КАЖДУЮ загрузку
// проверяет грант/группу/оплату/H3916-членство и только тогда отдаёт 302 на
// embed. ВНЕ auth-группы: is_free/is_preview легитимно отдаются гостю
// (публичный «пример урока» — единственная точка правды ShopController::preview),
// всё платное контроллер закрывает 404 сам. До catch-all /{slug}.
Route::get('/c/{slug}/u/{lessonId}/video/{player}', [RecordingGateController::class, 'show'])
    ->middleware('course.canonical')
    ->whereIn('player', ['youtube', 'rutube', 'kinescope', 'video'])
    ->name('student.recording.gate');

// --- ЛИЧНЫЙ КАБИНЕТ СТУДЕНТА (ЗАЩИЩЕНО) ---
Route::middleware(['auth', 'track.activity', 'student.maintenance'])->group(function () {

    Route::get('/home', function () {
        $user = auth()->user();
        if ($user->is_admin) {
            return redirect('/admin');
        }

        return redirect()->route('student.dashboard');
    })->name('home');

    Route::get('/calendar', [StudentController::class, 'calendar'])->name('student.calendar');
    Route::post('/calendar/feed/regenerate', [CalendarFeedController::class, 'regenerate'])
        ->name('student.calendar.feed.regenerate');
    // H2317 — предварительное предупреждение о занятии (не приду / не уверен /
    // опоздаю / уйду раньше). Контроллер 404 при features.attendance_notices OFF.
    Route::post('/calendar/{schedule}/notice', [AttendanceNoticeController::class, 'store'])
        ->whereNumber('schedule')
        ->name('student.calendar.notice.store');
    Route::delete('/calendar/{schedule}/notice', [AttendanceNoticeController::class, 'destroy'])
        ->whereNumber('schedule')
        ->name('student.calendar.notice.destroy');
    Route::get('/dvaram', [StudentController::class, 'dashboard'])->name('student.dashboard');

    Route::get('/dvaram/proverka', [CabinetMasteryController::class, 'show'])
        ->name('student.cabinet-mastery');
    Route::post('/dvaram/proverka', [CabinetMasteryController::class, 'submit'])
        ->name('student.cabinet-mastery.submit');

    Route::get('/dvaram/help', [StudentCabinetGuideController::class, 'show'])
        ->name('student.help');

    // Bounded student agent (H3231): homework hint / dictionary lookup /
    // cabinet FAQ only, no free chat. 404 while features.student_agent OFF.
    Route::post('/dvaram/agent', [StudentAgentController::class, 'run'])
        ->name('student.agent.run');

    Route::get('/open-lessons', [StudentController::class, 'openLessons'])->name('student.open-lessons');

    // Колоды / интервальные повторения (H211) — только при srs.enabled.
    // Canonical URL: /dvaram/koloda (legacy /dvaram/srs → 301).
    // Static segments (stats/decks) MUST be registered before {slug}.
    if (config('srs.enabled')) {
        Route::get('/dvaram/koloda/stats', [SrsController::class, 'stats'])
            ->name('student.srs.stats');

        Route::get('/dvaram/koloda/decks', [SrsController::class, 'decks'])
            ->name('student.srs.decks');

        Route::get('/dvaram/koloda/{slug}', [SrsController::class, 'review'])
            ->name('student.srs.deck');

        Route::get('/dvaram/koloda', [SrsController::class, 'cabinetIndex'])
            ->name('student.srs');

        // Legacy /dvaram/srs → /dvaram/koloda
        Route::permanentRedirect('/dvaram/srs', '/dvaram/koloda');
        Route::permanentRedirect('/dvaram/srs/stats', '/dvaram/koloda/stats');
        Route::permanentRedirect('/dvaram/srs/decks', '/dvaram/koloda/decks');
        Route::get('/dvaram/srs/{slug}', function (string $slug) {
            return redirect('/dvaram/koloda/'.$slug, 301);
        })->where('slug', '[A-Za-z0-9\-]+');
    }

    // H2110 — Wave 2: «Старт чтения» cohort reading packs INSIDE the cabinet.
    // Registered unconditionally (unlike the SRS block above) because the gate is
    // per-request entitlement, not a boot-time flag: ReadingPackController checks
    // features.kosha_reader AND StartChteniyaCohort::hasEntitlement($user), so a
    // logged-in student who has not bought the cohort gets 404, not a redirect.
    // Static segment before {slug}, same ordering discipline as /dvaram/koloda.
    // H2441 — Hindi programme playlist. Controller 404s while the flag is OFF.
    // Classic cabinet only: no hybrid /library intermediate.
    Route::get('/dvaram/programme/hindi', [HindiProgrammePlaylistController::class, 'hindi'])
        ->name('student.programme.hindi');

    // H2445 — «в колоду» from the playlist row. Item text is read from the
    // transcript extractor server-side. Not behind srs.enabled (H2106 fence).
    Route::post('/dvaram/programme/hindi/srs', [HindiMySrsDeckController::class, 'addLesson'])
        ->name('student.programme.hindi.srs');

    // H2446 — curated TG practice (JSON store, no live Telegram). Flag OFF → 404.
    Route::get('/dvaram/programme/hindi/chat-practice', [HindiTgCuratedPracticeController::class, 'show'])
        ->name('student.programme.hindi.tg');
    Route::post('/dvaram/programme/hindi/chat-practice/check', [HindiTgCuratedPracticeController::class, 'check'])
        ->name('student.programme.hindi.tg.check');

    // H3206 — Kostina module dictionaries. Static segment before {module}.
    Route::get('/dvaram/programme/hindi/vocab', [HindiDictionaryDrillsController::class, 'index'])
        ->name('student.programme.hindi.vocab');
    Route::get('/dvaram/programme/hindi/vocab/{module}', [HindiDictionaryDrillsController::class, 'show'])
        ->name('student.programme.hindi.vocab.show')
        ->where('module', 'M(?:[1-9]|1[0-2])');
    Route::post('/dvaram/programme/hindi/vocab/{module}/check', [HindiDictionaryDrillsController::class, 'check'])
        ->name('student.programme.hindi.vocab.check')
        ->where('module', 'M(?:[1-9]|1[0-2])');

    Route::get('/dvaram/reading', [ReadingPackController::class, 'cabinetIndex'])
        ->name('student.reading.index');

    // H2168 — per-COURSE reading packs (Nalopākhyāna 3 packs, Subhāṣita 1).
    // Registered BEFORE /dvaram/reading/{slug} on purpose: `kurs` would otherwise
    // match that wildcard, same static-segment-first discipline as /dvaram/koloda.
    // Gate is CourseCohortEntitlement::hasEntitlement($user, $course) — the course's
    // own `cohort_courses.<slug>.enabled` switch (default OFF) plus a real paid
    // payment for THAT course, so it is prod-inert until ops enables it and a
    // payment on one course never opens the other's reader.
    Route::get('/dvaram/reading/kurs/{course}', [ReadingPackController::class, 'courseIndex'])
        ->name('student.reading.course.index')
        ->where('course', '[a-z0-9_\-]+');

    Route::get('/dvaram/reading/kurs/{course}/{slug}', [ReadingPackController::class, 'coursePack'])
        ->name('student.reading.course.pack')
        ->where('course', '[a-z0-9_\-]+')
        ->where('slug', '[a-z0-9\-]+');

    Route::get('/dvaram/reading/{slug}', [ReadingPackController::class, 'cabinetShow'])
        ->name('student.reading.pack')
        ->where('slug', '[a-z0-9\-]+');

    // H2111 — «в колоду» from the tap-token panel. Same gate as the reader (flag +
    // entitlement, checked per request inside the controller), deliberately NOT behind
    // config('srs.enabled'): collecting a card must not require flipping the global SRS
    // switch (H2106 fence).
    Route::post('/dvaram/reading/{slug}/srs', [ReadingPackController::class, 'addToSrs'])
        ->name('student.reading.srs.add')
        ->where('slug', '[a-z0-9\-]+');

    // H2107 — fire-and-forget token-lookup telemetry (same gate, positions only).
    // Feeds student progress % and the teacher stalled-lemma view — see
    // App\Services\StartChteniyaProgress.
    Route::post('/dvaram/reading/{slug}/lookup', [ReadingPackController::class, 'logLookup'])
        ->name('student.reading.lookup')
        ->where('slug', '[a-z0-9\-]+');

    // H1680 — Wave 2: cabinet skill-drill strip, DISTINCT from the FSRS
    // review loop above (/dvaram/koloda) — short /lila drills linked from the
    // cabinet, no spaced-repetition scheduling here. Behind its own flag
    // (default OFF, Architecture §5: "any cabinet/SRS surfacing remains
    // OFF by default"), independent of srs.enabled.
    if (config('features.games_skill_drills')) {
        Route::get('/dvaram/skill-drills', [StudentController::class, 'skillDrills'])
            ->name('student.skill-drills');
    }

    // H2482 — native VisualDCS surfaces. Flags checked per request so one
    // surface can 404 without unregistering the other two.
    // H2493 — Grammar Lab explorer. Controller 404s when features.grammar_lab
    // is OFF and 403s (empty payload) when the user is not entitled.
    Route::get('/dvaram/grammar-lab', [GrammarLabController::class, 'index'])
        ->name('student.grammar-lab.index');
    Route::get('/dvaram/grammar-lab/search', [GrammarLabController::class, 'search'])
        ->name('student.grammar-lab.search');
    Route::get('/dvaram/grammar-lab/history', [GrammarLabController::class, 'history'])
        ->name('student.grammar-lab.history');
    Route::get('/dvaram/grammar-lab/t/{slug}', [GrammarLabController::class, 'show'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('student.grammar-lab.show');
    Route::get('/dvaram/grammar-lab/t/{slug}/compare', [GrammarLabController::class, 'compare'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('student.grammar-lab.compare');
    Route::post('/dvaram/grammar-lab/t/{slug}/bookmark', [GrammarLabController::class, 'bookmark'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('student.grammar-lab.bookmark');
    Route::get('/dvaram/grammar-lab/t/{slug}/practice', [GrammarLabController::class, 'practice'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('student.grammar-lab.practice');
    Route::post('/dvaram/grammar-lab/t/{slug}/practice', [GrammarLabController::class, 'attempt'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('student.grammar-lab.attempt');
    Route::post('/dvaram/grammar-lab/t/{slug}/srs', [GrammarLabController::class, 'addToSrs'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('student.grammar-lab.srs');
    Route::get('/dvaram/grammar-lab/pilot', [GrammarLabPilotController::class, 'intro'])
        ->name('student.grammar-lab.pilot');
    Route::post('/dvaram/grammar-lab/pilot', [GrammarLabPilotController::class, 'enroll'])
        ->name('student.grammar-lab.pilot.enroll');
    Route::post('/dvaram/grammar-lab/pilot/confusion', [GrammarLabPilotController::class, 'confusion'])
        ->name('student.grammar-lab.pilot.confusion');

    Route::get('/dvaram/visualdcs', [VisualDcsController::class, 'hub'])
        ->name('student.visualdcs.hub');
    Route::get('/dvaram/visualdcs/{surface}', [VisualDcsController::class, 'index'])
        ->where('surface', 'verb|nominal|passage')
        ->name('student.visualdcs.index');
    Route::get('/dvaram/visualdcs/{surface}/{id}', [VisualDcsController::class, 'show'])
        ->where('surface', 'verb|nominal|passage')
        ->where('id', '.+')
        ->name('student.visualdcs.show');
    Route::post('/dvaram/visualdcs/{surface}/{id}/progress', [VisualDcsController::class, 'storeProgress'])
        ->where('surface', 'verb|nominal|passage')
        ->where('id', '.+')
        ->name('student.visualdcs.progress');

    // H987 — RQ4 user study (on-ramp-first vs Талмуд-first). За фича-флагом
    // features.rq4_study, ВЫКЛ по умолчанию (404 пока не включен).
    Route::prefix('rq4-study')->name('rq4.')->group(function () {
        Route::get('/', [Rq4StudyController::class, 'intro'])->name('intro');
        Route::post('/enroll', [Rq4StudyController::class, 'enroll'])->name('enroll');
        Route::get('/start-arm', [Rq4StudyController::class, 'startArm'])->name('start-arm');
        Route::get('/diagnostic/{phase}', [Rq4StudyController::class, 'diagnostic'])->name('diagnostic');
        Route::post('/diagnostic/{phase}', [Rq4StudyController::class, 'submitAnswer'])->name('diagnostic.submit');
    });

    Route::get('/messages', [StudentController::class, 'messages'])->name('student.messages');

    // H2747 — Phase 0/1 consented callback request (H2486 packet §4.3/§8).
    // Controller 404s while features.telephony_callback_request is OFF.
    // No provider HTTP, no PSTN — writes FollowUpTask + CallEvent::requested only.
    Route::get('/support/callback', [CallbackRequestController::class, 'show'])
        ->name('student.support.callback');
    Route::post('/support/callback', [CallbackRequestController::class, 'store'])
        ->name('student.support.callback.store');

    // H1481 hybrid chassis (R29 Phase 1): job-named pages. Controllers 404 when
    // features.cabinet_hybrid is OFF so prod is unchanged until the flag flips.
    Route::get('/library', [StudentController::class, 'library'])->name('student.library');

    // Короткая help-страница «Почему баланс праны уменьшился?» (H1756) —
    // закрывает частый вопрос поддержки про сгорание/списания праны.
    Route::view('/help/prana-balance', 'help.prana-balance')->name('help.prana-balance');

    // Старый URL → канон /faq/dz (публичный FAQ; в кабинете «как сдавать» тоже туда).
    Route::redirect('/help/homework', '/faq/dz', 301)->name('help.homework');
    Route::get('/progress', [StudentController::class, 'progress'])->name('student.progress');
    Route::get('/access', [StudentController::class, 'access'])->name('student.access');

    // Клубное членство (H2644): самостоятельный отказ от продления и возврат.
    // Флаг features.membership_cancellation проверяется в контроллере (404).
    Route::post('/membership/cancel', [MembershipController::class, 'cancel'])
        ->name('student.membership.cancel');
    Route::post('/membership/resume', [MembershipController::class, 'resume'])
        ->name('student.membership.resume');

    // Короткие URL кабинета: /c/{slug}, /c/{slug}/u/{lessonId} (урок).
    // Legacy /course/... → 301 (см. блок ниже, внутри auth).
    Route::get('/c/{slug}', [StudentController::class, 'showCourse'])
        ->middleware('course.canonical')
        ->name('student.course');
    Route::post('/c/{slug}/access/materialize', [AccessSelfServiceController::class, 'materialize'])
        ->middleware('course.canonical')
        ->name('student.access.materialize');
    Route::get('/c/{slug}/u/{lessonId}', [StudentController::class, 'showLesson'])
        ->middleware('course.canonical')
        ->name('student.lesson');
    Route::get('/c/{slug}/u/{lessonId}/drills', [HindiTranscriptDrillsController::class, 'show'])
        ->middleware('course.canonical')
        ->name('student.lesson.drills');
    Route::post('/c/{slug}/u/{lessonId}/drills/check', [HindiTranscriptDrillsController::class, 'check'])
        ->middleware('course.canonical')
        ->name('student.lesson.drills.check');
    Route::post('/c/{slug}/u/{lessonId}/srs', [HindiMySrsDeckController::class, 'addItem'])
        ->middleware('course.canonical')
        ->name('student.lesson.srs.add');

    // H3521: Learn Your Way — персонализированный пак занятия. Default-OFF
    // (LYW_ENABLED): флаг выключен => 404 и вкладка на уроке не рендерится.
    Route::get('/c/{slug}/u/{lessonId}/learn', [LessonPackController::class, 'show'])
        ->middleware('course.canonical')
        ->name('student.lesson.lessonpack');

    Route::post('/c/{slug}/u/{lessonId}/complete', [StudentController::class, 'completeLesson'])
        ->name('student.lesson.complete');

    // H3308: гейт-выдача контента урока, снятого с публичного диска —
    // стенограмма, материалы, справочные файлы ДЗ. Гейт тот же, что у плеера.
    Route::get('/c/{slug}/u/{lessonId}/transcript', [GatedAssetController::class, 'transcript'])
        ->whereNumber('lessonId')
        ->name('student.lesson.transcript');
    Route::get('/c/{slug}/u/{lessonId}/materials/{file}', [GatedAssetController::class, 'material'])
        ->whereNumber('lessonId')->where('file', '[A-Za-z0-9._-]+')
        ->name('student.lesson.material');
    Route::get('/c/{slug}/u/{lessonId}/homework-files/{file}', [GatedAssetController::class, 'homeworkRef'])
        ->whereNumber('lessonId')->where('file', '[A-Za-z0-9._-]+')
        ->name('student.lesson.homework-file');

    Route::get('/c/{slug}/materials/download', [StudentController::class, 'downloadCourseMaterials'])
        ->middleware('course.canonical')
        ->name('student.course.materials.download');

    // Библиотека курса — реестр ссылок на литературу (файлы лежат не у нас).
    // За флагом features.course_library; выключен — 404.
    Route::get('/c/{slug}/library', [StudentController::class, 'courseLibrary'])
        ->middleware('course.canonical')
        ->name('student.course.library');

    Route::post('/c/{slug}/u/{lessonId}/note', [StudentController::class, 'saveNote'])
        ->name('student.lesson.note');

    // Домашние задания: сдача студентом + контролируемое скачивание файлов
    Route::post('/c/{slug}/u/{lessonId}/homework', [HomeworkController::class, 'store'])
        ->name('student.homework.store');

    // Legacy path 301 → short /c/... (только GET; POST остаются на новых action).
    Route::get('/course/{slug}', function (string $slug) {
        $course = Course::resolveBySlug($slug);
        abort_if($course === null, 404);

        return redirect()->route('student.course', $course->slug, 301);
    });
    Route::get('/course/{slug}/lesson/{lessonId}', function (string $slug, $lessonId) {
        $course = Course::resolveBySlug($slug);
        abort_if($course === null, 404);

        return redirect()->route('student.lesson', [$course->slug, $lessonId], 301);
    });
    Route::get('/homework/file/{file}', [HomeworkController::class, 'download'])
        ->name('homework.file.download');
    Route::get('/homework/submission/{submission}/images-pdf', [HomeworkController::class, 'downloadImagesPdf'])
        ->name('homework.submission.images-pdf');
    Route::delete('/homework/file/{file}', [HomeworkController::class, 'destroyFile'])
        ->name('homework.file.destroy');
    Route::post('/homework/file/{file}/move', [HomeworkController::class, 'moveFile'])
        ->name('homework.file.move');
    Route::delete('/homework/comment/{comment}', [HomeworkController::class, 'destroyComment'])
        ->name('homework.comment.destroy');

    Route::post('/api/heartbeat', [HeartbeatController::class, 'store'])
        ->name('activity.heartbeat');

    // Baseline-телеметрия ремейка кабинета (H962, спека §4): клиентские клики/
    // импрешены из первопартийного JS (никаких сторонних трекеров, R20).
    Route::post('/dvaram/telemetry', [CabinetTelemetryController::class, 'store'])
        ->name('student.telemetry');

    // Самообслуживание должника: студент сам гасит согласованную рассрочку/обещание.
    // Плоский долг «не продлил» идёт штатным /checkout/{tariff} (см. DebtPaymentResolver).
    Route::post('/debt/promise/{promise}/pay', [DebtPaymentController::class, 'payPromise'])
        ->name('student.debt.promise.pay');
    Route::post('/debt/promise/{promise}/reschedule', [DebtPaymentController::class, 'reschedule'])
        ->name('student.debt.promise.reschedule');
    Route::post('/debt/course/{course}/pay-all', [DebtPaymentController::class, 'payAll'])
        ->name('student.debt.course.pay-all');
    Route::post('/debt/course/{course}/pay-bundle', [DebtPaymentController::class, 'payBundle'])
        ->name('student.debt.course.pay-bundle');

    // P2P-перевод праны другому студенту (подарок).
    Route::post('/prana/transfer', [PranaTransferController::class, 'transfer'])
        ->middleware('throttle:20,1')
        ->name('student.prana.transfer');

    // Магазин праны: покупка перка за прану.
    Route::post('/prana/redeem/{perk}', [PranaShopController::class, 'redeem'])
        ->middleware('throttle:20,1')
        ->name('student.prana.redeem');

    Route::get('/certificate/{id}/download', [StudentController::class, 'downloadCertificate'])
        ->name('student.certificate.download');

    Route::get('/certificate/{id}/download/jpg', [StudentController::class, 'downloadCertificateImage'])
        ->name('student.certificate.download.jpg');

    Route::get('/admin/leads/export', [LeadController::class, 'export'])
        ->middleware('admin')
        ->name('leads.export');

    // H3313: GET — инструкция, выдача токена только CSRF-защищённым POST.
    Route::get('/telegram/connect', [TelegramController::class, 'connect'])->name('telegram.connect');
    Route::post('/telegram/connect', [TelegramController::class, 'start'])->name('telegram.connect.start');

    // Привязка VK через одноразовый токен (вместо сырого ?ref={user_id}) — см. VkController.
    Route::get('/vk/connect', [VkController::class, 'connect'])->name('vk.connect');
    Route::post('/vk/connect', [VkController::class, 'start'])->name('vk.connect.start');

    // Отвязка мессенджера (TG/VK) из кабинета — кнопка «Отвязать»
    Route::post('/profile/messenger/{channel}/disconnect', [StudentController::class, 'disconnectMessenger'])
        ->whereIn('channel', ['telegram', 'vk'])
        ->name('student.messenger.disconnect');

    // Самостоятельная смена пароля студентом в кабинете
    Route::post('/profile/password', [AuthController::class, 'updatePassword'])
        ->name('student.password.update');

    // H4434 — timezone localization (MG 09-09-2026): ручной селектор + временное
    // пребывание + silent device-TZ захват (VPN-иммунный сигнал).
    Route::post('/profile/timezone', [TimezoneController::class, 'update'])
        ->name('student.timezone.update');
    Route::post('/profile/timezone/override', [TimezoneController::class, 'override'])
        ->name('student.timezone.override');
    Route::post('/profile/timezone/override/clear', [TimezoneController::class, 'clearOverride'])
        ->name('student.timezone.override.clear');
    Route::post('/profile/timezone/device', [TimezoneController::class, 'deviceCapture'])
        ->name('student.timezone.device');
});
