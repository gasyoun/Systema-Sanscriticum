<?php

namespace App\Http;

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CaptureAttribution;
use App\Http\Middleware\CapturePartnerReferral;
use App\Http\Middleware\CaptureReferral;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\ImpersonationGuard;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RedirectToCanonicalCourseSlug;
use App\Http\Middleware\StudentMaintenance;
use App\Http\Middleware\TrackUserActivity;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidateSignature;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Middleware\VerifyExamScoresWebhook;
use App\Http\Middleware\VerifyInboundEmailWebhook;
use App\Http\Middleware\VerifyLeadStepWebhook;
use App\Http\Middleware\VerifyLectureClipCallbackWebhook;
use App\Http\Middleware\VerifyMaxMagnetWebhook;
use App\Http\Middleware\VerifyPartnerBotWebhook;
use App\Http\Middleware\VerifyTelegramBotWebhook;
use App\Http\Middleware\VerifyTelegramMagnetWebhook;
use App\Http\Middleware\VerifyTelegramZapisiWebhook;
use App\Http\Middleware\VerifyVkBotWebhook;
use App\Http\Middleware\VerifyVkMagnetCallback;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        TrustProxies::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            // Границы режима просмотра за пользователя (H1947): fail-closed при
            // снятом флаге, запрет денежных записей, плашка режима в HTML.
            // Стоит ДО SubstituteBindings осознанно: запрет не должен зависеть от
            // того, нашлась ли модель в URL, иначе денежный POST по несуществующему
            // slug'у отвечал бы 404 мимо гейта. Панель Filament включает его
            // отдельно — группу `web` она не берёт.
            ImpersonationGuard::class,
            SubstituteBindings::class,
            CaptureReferral::class,
            CapturePartnerReferral::class,
            CaptureAttribution::class,
        ],

        'api' => [
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            ThrottleRequests::class.':api',
            SubstituteBindings::class,
        ],
    ];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used instead of class names to conveniently assign middleware to routes and groups.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        'auth' => Authenticate::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'auth.session' => AuthenticateSession::class,
        'cache.headers' => SetCacheHeaders::class,
        'can' => Authorize::class,
        'guest' => RedirectIfAuthenticated::class,
        'password.confirm' => RequirePassword::class,
        'precognitive' => HandlePrecognitiveRequests::class,
        'signed' => ValidateSignature::class,
        'throttle' => ThrottleRequests::class,
        'verified' => EnsureEmailIsVerified::class,
        'admin' => AdminMiddleware::class,
        // --- ТРЕКИНГ АКТИВНОСТИ СТУДЕНТОВ ---
        'track.activity' => TrackUserActivity::class,
        // --- ТЕХОБСЛУЖИВАНИЕ КАБИНЕТА ---
        'student.maintenance' => StudentMaintenance::class,
        // --- LEAD-MAGNET WEBHOOKS ---
        'verify.tg.magnet' => VerifyTelegramMagnetWebhook::class,
        'verify.vk.magnet' => VerifyVkMagnetCallback::class,
        'verify.max.magnet' => VerifyMaxMagnetWebhook::class,
        'verify.n8n.leadstep' => VerifyLeadStepWebhook::class,
        'verify.n8n.clipcallback' => VerifyLectureClipCallbackWebhook::class,
        'verify.n8n.examscores' => VerifyExamScoresWebhook::class,
        // --- ЛЕГАСИ БОТ-ВЕБХУКИ (enforce-if-configured) ---
        'verify.tg.bot' => VerifyTelegramBotWebhook::class,
        'verify.vk.bot' => VerifyVkBotWebhook::class,
        // --- ПАРТНЁРСКИЙ БОТ (fail-closed когда partner.enabled) ---
        'verify.partner.bot' => VerifyPartnerBotWebhook::class,
        // --- TELEGRAM TRACK C: @zapisi_ORSbot (H164, D8) ---
        'verify.tg.zapisi' => VerifyTelegramZapisiWebhook::class,
        // --- ВХОДЯЩИЙ EMAIL (H3462): zabota@ → вебхук, секрет в пути ---
        'verify.inbound.email' => VerifyInboundEmailWebhook::class,
        // Канонический slug курса: alias в URL → 301 на courses.slug
        'course.canonical' => RedirectToCanonicalCourseSlug::class,
    ];
}
