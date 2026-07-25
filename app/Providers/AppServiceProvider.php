<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\ArticleView;
use App\Models\ContentCandidate;
use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LectureClip;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Schedule;
use App\Observers\ArticleViewObserver;
use App\Observers\ContentCandidateObserver;
use App\Observers\LandingPageObserver;
use App\Observers\LeadAuditObserver;
use App\Observers\LectureClipObserver;
use App\Observers\LessonObserver;
use App\Observers\PaymentAuditObserver;
use App\Observers\PaymentDealBridgeObserver;
use App\Observers\PaymentObserver;
use App\Observers\PaymentTelemetryObserver;
use App\Observers\ScheduleObserver;
use App\Observers\SitemapCacheInvalidator;
use App\Services\Lecture\LectureAiClient;
use App\Services\Lecture\LectureBuilderClient;
use App\Services\Webinar\WebinarProvider;
use App\Services\Zoom\ZoomService;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\WebDAV\WebDAVAdapter;
use Sabre\DAV\Client;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            LectureBuilderClient::class,
            fn () => LectureBuilderClient::fromConfig(),
        );
        $this->app->singleton(
            LectureAiClient::class,
            fn () => LectureAiClient::fromConfig(),
        );
        // bind (не singleton): креды читаются из config на момент резолва —
        // важно для тестов, где config('services.zoom.*') ставится в setUp.
        $this->app->bind(
            ZoomService::class,
            fn () => ZoomService::fromConfig(),
        );
        // Шов GC-B3: до руления о смене провайдера дефолтный драйвер — Zoom.
        $this->app->bind(
            WebinarProvider::class,
            fn () => ZoomService::fromConfig(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // --- 1. ИСПРАВЛЕНИЕ КРИТ 3: Умное включение HTTPS ---
        // Включаем https только если сайт на продакшене (APP_ENV=production)
        // или если мы явно попросили об этом через конфиг.
        if (app()->isProduction() || config('app.force_https', false)) {
            URL::forceScheme('https');
        }

        if (str_contains(config('app.url'), 'trycloudflare.com')) {
            URL::forceScheme('https');
        }
        // ----------------------------------------------------

        // 2. Наблюдатель
        Schedule::observe(ScheduleObserver::class);

        // 3. Локаль
        Carbon::setLocale('ru');

        ArticleView::observe(ArticleViewObserver::class);

        Payment::observe(PaymentObserver::class);

        // Аудит финансовых операций (кто/что/когда правил платёж).
        Payment::observe(PaymentAuditObserver::class);

        // Baseline-телеметрия ремейка кабинета (H962): access.renewal.complete.
        Payment::observe(PaymentTelemetryObserver::class);

        // GC-C1 (H1641): состоявшаяся оплата закрывает сделку. Ранг 4 —
        // только читает денежный переход, пишет в deals/deal_transitions.
        // Инертен, пока crm_pipeline_board ВЫКЛ.
        Payment::observe(PaymentDealBridgeObserver::class);

        // Аудит CRM-воронки (кто/что/когда правил заявку).
        Lead::observe(LeadAuditObserver::class);

        LandingPage::observe(LandingPageObserver::class);

        // Сброс sitemap.xml-кэша при изменении контента, который туда входит.
        LandingPage::observe(SitemapCacheInvalidator::class);
        Course::observe(SitemapCacheInvalidator::class);
        Article::observe(SitemapCacheInvalidator::class);

        // n8n lecture content engine (H1547, Wave 1): публикация лекции →
        // ranked-span нарезка (если флаги ВКЛ); LectureClip → ContentCandidate
        // зеркалирование (всегда, дешёвая идемпотентная запись).
        Lesson::observe(LessonObserver::class);
        LectureClip::observe(LectureClipObserver::class);

        // n8n lecture content engine (H1548, Wave 2): accepted clip → social
        // draft; accepted social draft → PublishSocialPostJob dispatch.
        ContentCandidate::observe(ContentCandidateObserver::class);

        // Мини-блок «Курсы в записи»: главная, legacy-лендинги и builder-блок
        View::composer(['main', 'promo.show', 'promo.legacy', 'promo.blocks.recorded_courses_block'], function ($view): void {
            if (array_key_exists('recordedCoursesMini', $view->getData())) {
                return;
            }

            $courses = Cache::remember('recorded_courses_mini_v2', 300, function () {
                return Course::query()
                    ->where('is_visible', true)
                    ->where('format', 'recorded')
                    ->with([
                        'tariffs' => fn ($q) => $q->where('is_active', true)->orderBy('price'),
                        'categories:id,name,slug,color',
                    ])
                    ->latest('id')
                    ->limit(24)
                    ->get();
            });

            $view->with('recordedCoursesMini', $courses);
        });

        // Диск «yandex_disk» — off-site назначение еженедельного бэкапа (WebDAV,
        // тот же аккаунт, что синхронизируется на ПК через десктоп-клиент Яндекс.Диска).
        // Требует YANDEX_DISK_LOGIN/YANDEX_DISK_APP_PASSWORD — см. .env.example.
        Storage::extend('webdav', function ($app, array $config) {
            $client = new Client([
                'baseUri' => $config['baseUri'],
                'userName' => $config['username'] ?? null,
                'password' => $config['password'] ?? null,
            ]);

            $adapter = new WebDAVAdapter($client, $config['prefix'] ?? '');

            return new LaravelFilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }
}
