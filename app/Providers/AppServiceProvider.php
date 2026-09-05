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
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Schedule;
use App\Observers\ArticleViewObserver;
use App\Observers\ContentCandidateObserver;
use App\Observers\CourseCoverWebpObserver;
use App\Observers\LandingPageObserver;
use App\Observers\LeadAuditObserver;
use App\Observers\LectureClipObserver;
use App\Observers\LessonObserver;
use App\Observers\MessageTemplateAuditObserver;
use App\Observers\PaymentAuditObserver;
use App\Observers\PaymentDealBridgeObserver;
use App\Observers\PaymentObserver;
use App\Observers\PaymentTelemetryObserver;
use App\Observers\ScheduleObserver;
use App\Observers\SitemapCacheInvalidator;
use App\Services\Lecture\LectureAiClient;
use App\Services\Lecture\LectureBuilderClient;
use App\Services\Payments\HttpPaypalWebhookSignatureVerifier;
use App\Services\Payments\PaypalWebhookSignatureVerifier;
use App\Services\Payroll\PayrollRateCalculator;
use App\Services\Support\Faq\EmbeddingProvider;
use App\Services\Support\Faq\NullEmbeddingProvider;
use App\Services\Support\Faq\OllamaEmbeddingProvider;
use App\Services\Telegram\DaemonProcessProbe;
use App\Services\Telegram\ProcDaemonProcessProbe;
use App\Services\Webinar\WebinarProvider;
use App\Services\Zoom\ZoomService;
use App\Support\Backup\BackupRunCommand;
use App\Support\Deploy\DeployDriftInspector;
use App\Support\NextIntroSession;
use App\Support\ServerGuards\ShellSystemInspector;
use App\Support\ServerGuards\SystemInspector;
use Filament\Support\View\Components\Modal;
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
use Spatie\Backup\Commands\BackupCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // H3532: калькулятор формул «на руки» читает сгенерированный
        // config/teacher_rates.php на момент резолва (тесты подменяют config).
        $this->app->bind(
            PayrollRateCalculator::class,
            fn () => new PayrollRateCalculator((array) config('teacher_rates')),
        );
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

        // H1914/H1931: SystemInspector — шов для cabinet:probe / guards:verify.
        // bind (не singleton): тесты подставляют FakeSystemInspector через
        // $this->app->instance(...) и доказывают, что critical-находка доходит
        // до вердикта пробы (раньше был hard-coded `new ShellSystemInspector`).
        $this->app->bind(
            PaypalWebhookSignatureVerifier::class,
            HttpPaypalWebhookSignatureVerifier::class,
        );
        $this->app->bind(SystemInspector::class, ShellSystemInspector::class);

        // H3803: путь к чекауту — base_path(), но через контейнер, чтобы тест
        // подменял инспектора подклассом и не зависел от того, есть ли под
        // рукой git-remote.
        $this->app->singleton(
            DeployDriftInspector::class,
            fn () => new DeployDriftInspector(base_path()),
        );

        // H3121: тот же шов для надзора за демоном MadelineProto. bind, а не
        // singleton, по той же причине — тест подменяет пробу на фейковую и
        // доказывает, что демон в ЧУЖОЙ cgroup будет погашен, не имея под
        // рукой ни /proc, ни systemd.
        $this->app->bind(DaemonProcessProbe::class, ProcDaemonProcessProbe::class);

        // H3195 / FINDINGS §513: Spatie Zip::addFile defaults to LENGTH_TO_END,
        // so a live storage/app member that shrinks between add and close()
        // fails backup:run with ER_DATA_LENGTH. Bind our command so the zip
        // path snapshots each member next to the archive before addFile.
        $this->app->bind(BackupCommand::class, BackupRunCommand::class);

        // H4001 (Wave 3 leverage-плана): dense-нога FAQ-ретривала. bind, а не
        // singleton: driver читается из config на момент резолва — тесты и
        // config:cache на проде подменяют ногу без пересборки контейнера.
        // null/неизвестный драйвер → NullEmbeddingProvider (BM25-пол).
        $this->app->bind(EmbeddingProvider::class, function () {
            return match ((string) config('knowledge.driver')) {
                'ollama' => new OllamaEmbeddingProvider,
                default => new NullEmbeddingProvider,
            };
        });
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

        // Модалки админки не закрываются кликом по затемнению. Закрытие вызывает
        // unmountAction() → array_pop(mountedActionsData), то есть введённые данные
        // стираются НА СЕРВЕРЕ: перехватить и вернуть их нечем. Случай из жизни —
        // преподаватель писал длинный отзыв к домашней работе, случайно кликнул мимо
        // и потерял текст целиком.
        //
        // Esc сознательно оставлен рабочим: это WAI-ARIA-паттерн диалога и осознанный
        // жест, в отличие от случайного клика. Закрыть модалку по-прежнему можно
        // крестиком, кнопкой отмены и Esc.
        //
        // Правило глобальное (обе панели + любые x-filament::modal). Если конкретному
        // действию старое поведение нужно — вернуть точечно:
        // ->closeModalByClickingAway(true) перекрывает этот дефолт.
        Modal::closedByClickingAway(false);

        ArticleView::observe(ArticleViewObserver::class);

        // Обложка витрины переводится в WebP в том же запросе, в котором её
        // загрузили (H3082). Второй рубеж — ежедневная media:covers-to-webp.
        Course::observe(CourseCoverWebpObserver::class);

        Payment::observe(PaymentObserver::class);

        // Аудит финансовых операций (кто/что/когда правил платёж).
        Payment::observe(PaymentAuditObserver::class);

        // Аудит контура «Расходы ИП» (H4188) — конвенции payment_audits.
        \App\Models\IpExpense::observe(\App\Observers\IpExpenseAuditObserver::class);

        // Baseline-телеметрия ремейка кабинета (H962): access.renewal.complete.
        Payment::observe(PaymentTelemetryObserver::class);

        // GC-C1 (H1641): состоявшаяся оплата закрывает сделку. Ранг 4 —
        // только читает денежный переход, пишет в deals/deal_transitions.
        // Инертен, пока crm_pipeline_board ВЫКЛ.
        Payment::observe(PaymentDealBridgeObserver::class);

        // Аудит CRM-воронки (кто/что/когда правил заявку).
        Lead::observe(LeadAuditObserver::class);

        // Аудит библиотеки шаблонов (кто/что/когда правил текст, H1932).
        MessageTemplate::observe(MessageTemplateAuditObserver::class);

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

        // H2365: site-wide free-intro / trial CTA date on all shop surfaces.
        // Empty source → banner omits the date line (H2760). Never invent a date.
        View::composer('layouts.shop', function ($view): void {
            if (array_key_exists('nextIntro', $view->getData())) {
                return;
            }
            $view->with('nextIntro', NextIntroSession::resolve());
        });

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
                // КРИТИЧНО (прод 22-08-2026): без явного AUTH_BASIC sabre ходит
                // на автонеготиации — первый PUT уходит БЕЗ Authorization
                // (CURLOPT_VERBOSE: «upload completely sent off … < HTTP/1.1
                // 401 Unauthorized»), Яндекс отвечает 401 уже после тела, curl
                // не может переиграть запрос → «necessary data rewind was not
                // possible», а часть фронтендов отвечала 2xx вообще ничего не
                // сохранив («призрачные успехи» всей этой саги). BASIC в
                // первом же запросе снимает весь класс проблем.
                'authType' => Client::AUTH_BASIC,
            ]);

            // 1) Мёртвые стволы TCP: PUT висел с недренируемым Send-Q часами.
            //    Рвём: коннект дольше 30 с; скорость ниже 1 КБ/с дольше 180 с
            //    (здоровая выгрузка ~230 КБ/с порог не задевает).
            // 2) FOLLOWLOCATION выключен: редирект на PUT обязан быть ошибкой,
            //    а не молчаливой сменой метода.
            $client->addCurlSetting(CURLOPT_CONNECTTIMEOUT, 30);
            $client->addCurlSetting(CURLOPT_LOW_SPEED_LIMIT, 1024);
            $client->addCurlSetting(CURLOPT_LOW_SPEED_TIME, 180);
            $client->addCurlSetting(CURLOPT_FOLLOWLOCATION, false);
            // 3) Жёсткий потолок на ВЕСЬ запрос (H3410, прод 24-08-2026): strace
            //    показал TLS sendto() EAGAIN-цикл на застрявшем сокете без
            //    прогресса 30+ минут — дольше, чем должен был пережить
            //    LOW_SPEED_TIME=180. Не отменяет п.1 (тот ловит МЕДЛЕННУЮ
            //    передачу раньше), а подстраховывает на случай, если конкретно
            //    эта EAGAIN-форма стагнации не считается «низкой скоростью» с
            //    точки зрения curl. Часть ≤20 МиБ на здоровом канале укладывается
            //    в секунды; 300 с оставляет щедрый запас и гарантированно рвёт
            //    застрявший сокет раньше следующего docker/cron-тика.
            $client->addCurlSetting(CURLOPT_TIMEOUT, 300);

            $adapter = new WebDAVAdapter($client, $config['prefix'] ?? '');

            return new LaravelFilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }
}
