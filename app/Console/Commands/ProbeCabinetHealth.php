<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CabinetProbeRun;
use App\Models\Course;
use App\Models\HomeworkComment;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\User;
use App\Services\HomeworkService;
use App\Services\CuratorNotifier;
use App\Support\Deploy\DeployDriftInspector;
use App\Support\Roles;
use App\Support\ServerGuards\CabinetProbeAlertState;
use App\Support\ServerGuards\GuardFinding;
use App\Support\ServerGuards\GuardSpec;
use App\Support\ServerGuards\ServerGuardsAuditor;
use App\Support\ServerGuards\SoftAlertWebhookNotifier;
use App\Support\ServerGuards\SoftFailureFingerprint;
use App\Support\ServerGuards\SystemInspector;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Пульс кабинета (H1777 + H1794): public URLs + manager + optional student,
 * history, TG (critical/soft), healthchecks, runbook.
 *
 * Deferred: Playwright, auto-restart, public status page.
 */
class ProbeCabinetHealth extends Command
{
    protected $signature = 'cabinet:probe
        {--dry : Прогнать проверки, не слать healthchecks/Telegram и не писать history}
        {--force-alert : Игнорировать TG-cooldown (critical и soft)}
        {--no-alert : Не слать Telegram (deploy.sh — сторож */15 остаётся ртом)}
        {--fail-on-critical : Exit 1 if an HTTP/cabinet surface failed (deploy.sh)}';

    protected $description = 'Пульс кабинета: public + manager (+ student) surfaces, history, TG';

    public function handle(): int
    {
        if (\PHP_OS_FAMILY === 'Windows' && \function_exists('sapi_windows_cp_set')) {
            @sapi_windows_cp_set(65001);
        }

        $started = hrtime(true);
        /** @var list<array{message: string, severity: string}> $failures */
        $failures = [];

        try {
            $failures = array_merge($failures, $this->probeSurfacesList(
                config('cabinet_probe.public_surfaces', []),
                authenticated: false,
            ));

            $mgrEmail = User::normalizeEmail((string) config('services.test_manager.email', ''));
            $mgrPass = (string) config('services.test_manager.password', '');

            if ($mgrPass === '' || $mgrEmail === '') {
                $this->comment('TEST_MANAGER_* пусты — manager-ветка пропущена.');
            } else {
                $failures = array_merge($failures, $this->probeAuth(
                    $mgrEmail,
                    $mgrPass,
                    expectedRole: Roles::MANAGER,
                    label: 'manager',
                ));
                if (! $this->hasCritical($failures)) {
                    $surfaces = config('cabinet_probe.surfaces', []);
                    if (config('features.cabinet_hybrid')) {
                        $surfaces = array_merge($surfaces, config('cabinet_probe.hybrid_surfaces', []));
                    }
                    $failures = array_merge($failures, $this->probeSurfacesList($surfaces, authenticated: true));
                }
                Auth::logout();
            }

            $stuEmail = User::normalizeEmail((string) config('services.test_student.email', ''));
            $stuPass = (string) config('services.test_student.password', '');
            if ($stuPass !== '' && $stuEmail !== '') {
                $failures = array_merge($failures, $this->probeAuth(
                    $stuEmail,
                    $stuPass,
                    expectedRole: null,
                    label: 'student',
                ));
                if (! $this->hasAuthFailure($failures, 'student')) {
                    $failures = array_merge($failures, $this->probeSurfacesList(
                        config('cabinet_probe.student_surfaces', []),
                        authenticated: true,
                    ));
                    $failures = array_merge($failures, $this->probeHomeworkUploadSynthetic());
                }
                Auth::logout();
            } else {
                $this->comment('TEST_STUDENT_* пусты — student-ветка пропущена.');
            }

            $failures = array_merge($failures, $this->probeDeployDrift());
            $failures = array_merge($failures, $this->probeServerGuards());
            $failures = array_merge($failures, $this->probeOutboundPaymentTls());
            $failures = array_merge($failures, $this->probeScheduleLinks());
        } catch (Throwable $e) {
            $failures[] = ['message' => 'probe crashed: '.$e->getMessage(), 'severity' => 'critical'];
            Log::error('cabinet:probe crashed', ['error' => $e->getMessage()]);
        } finally {
            Auth::logout();
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $criticalFails = array_values(array_filter($failures, fn ($f) => ($f['severity'] ?? 'critical') === 'critical'));
        $softFails = array_values(array_filter($failures, fn ($f) => ($f['severity'] ?? '') === 'soft'));
        $healthy = $failures === [];
        $criticalHealthy = $criticalFails === [];

        if ($healthy) {
            $this->info('Кабинет жив: все проверки OK ('.$durationMs.' ms).');
        } else {
            $this->warn('Кабинет болен ('.$durationMs.' ms):');
            foreach ($failures as $f) {
                $this->line('  • ['.($f['severity'] ?? '?').'] '.$f['message']);
            }
            Log::error('cabinet:probe failed', ['failures' => $failures]);
        }

        if (! $this->option('dry')) {
            $this->recordHistory($criticalHealthy && $softFails === [], $criticalHealthy, $durationMs, $failures);
        }

        // HTTP/cabinet 5xx vs host guards (tmpfs, backup, earlyoom, …).
        // Host-only must not SOS, must not /fail Better Stack, must not
        // fail deploy.sh — that is the 19–20-08 spam class (H3197).
        $httpCritical = array_values(array_filter(
            $criticalFails,
            fn ($f) => ! $this->isHostGuardFailure($f),
        ));
        $hostCritical = array_values(array_filter(
            $criticalFails,
            fn ($f) => $this->isHostGuardFailure($f),
        ));
        $httpHealthy = $httpCritical === [];

        $this->reportToHealthchecks($httpHealthy, array_column($httpCritical, 'message'));
        if (! $this->option('no-alert')) {
            $this->reportToTelegram($httpHealthy, $httpCritical, array_merge($softFails, $hostCritical));
        } else {
            $this->comment('TG: --no-alert (сторож */15 шлёт, если надо)');
        }

        if ($this->option('fail-on-critical') && ! $httpHealthy) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{message: string, severity: string}>
     */
    private function probeAuth(string $email, string $password, ?string $expectedRole, string $label): array
    {
        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            return [['message' => "$label: user $email не найден — users:ensure-test-{$label}?", 'severity' => 'critical']];
        }

        if ($expectedRole === Roles::MANAGER) {
            if ($user->role !== Roles::MANAGER) {
                return [['message' => "$label: role={$user->role}, ожидали manager", 'severity' => 'critical']];
            }
        } else {
            // Student: not staff roles.
            if (in_array($user->role, [Roles::SUPER_ADMIN, Roles::ADMIN, Roles::MANAGER, Roles::ACCOUNTANT, Roles::TEACHER], true)) {
                return [['message' => "$label: role={$user->role} — не студент", 'severity' => 'critical']];
            }
        }

        if (! Hash::check($password, $user->password)) {
            return [['message' => "$label: пароль не сходится с .env", 'severity' => 'critical']];
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            return [['message' => "$label: Auth::attempt отказал", 'severity' => 'critical']];
        }

        if (Auth::id() !== $user->id) {
            return [['message' => "$label: после attempt auth user чужой", 'severity' => 'critical']];
        }

        return [];
    }

    /**
     * Ресурсные предохранители ОС (H1914) как ещё одна поверхность этой пробы.
     *
     * Предохранители 29-07-2026 живут вне репозитория и вне приложения; пересборка
     * LXC сносит их молча. Проба — единственный контур, который ходит каждые 15
     * минут ОТДЕЛЬНОЙ строкой cron (то есть переживает зависший планировщик) и уже
     * имеет историю + Telegram. Поэтому пропажа предохранителя приезжает сюда.
     *
     * @return list<array{message: string, severity: string}>
     */
    /**
     * H3803 — прод перестал получать новый код.
     *
     * Инцидент 31-08-2026: вшитый в URL `origin` PAT протух, `git pull` начал
     * отдавать 401, и `deploy.sh` падал на втором шаге. Всё, на что смотрят
     * мониторы, при этом оставалось зелёным — HTTP 200, чистое tracked-дерево,
     * непорванный предохранитель, живые guards, — потому что сайт работал; он
     * просто перестал обновляться. Симптом отрицательный: код НЕ приезжает, и
     * заметить это можно было только когда деплой понадобился.
     *
     * Две НЕЗАВИСИМЫЕ ноги, и порядок здесь принципиален:
     *
     * 1. Возраст последнего успешного `git fetch`. Ровно этот случай: когда
     *    fetch падает, ref `origin/main` замерзает ВМЕСТЕ с HEAD, отставание
     *    остаётся нулевым, и наивная проверка «HEAD против origin/main» рапортует
     *    здоровье. Устаревающий FETCH_HEAD — единственный локальный след.
     * 2. Отставание HEAD от origin/main. Противоположный отказ: fetch работает,
     *    а деплой нет (грязное дерево, сорванный предохранитель, красный
     *    preflight). Со скидкой по возрасту, чтобы только что смерженный PR и
     *    деплой в процессе не поднимали тревогу.
     *
     * Обе ноги локальные: проба ходит раз в 15 минут, и сетевой вызов на пути
     * health-чека сделал бы её зависимой от доступности GitHub.
     *
     * @return list<array{message: string, severity: string}>
     */
    private function probeDeployDrift(): array
    {
        if (! config('cabinet_probe.check_deploy_drift', true)) {
            return [];
        }

        // Это проверка ПРОДАКШЕН-выкладки. На дев-машине отставание от
        // origin/main — норма жизни, и проба там звенела бы постоянно, что
        // быстро приучает не читать её вывод.
        //
        // Сравниваем config('app.env'), а не app()->isProduction(): последний
        // читает env-биндинг контейнера, зафиксированный на бутстрапе, и
        // config(['app.env' => ...]) в тесте его не меняет — шов был бы
        // непроверяемым.
        if (config('app.env') !== 'production') {
            return [];
        }

        $inspector = app(DeployDriftInspector::class);
        if (! $inspector->isUsable()) {
            // Не git-чекаут или git не может разрешить origin/main.
            return [];
        }

        $failures = [];

        $fetchMaxAge = max(1, (int) config('cabinet_probe.deploy_drift_fetch_max_age_minutes', 90));
        $lastFetch = $inspector->lastFetchAt();

        if ($lastFetch === null) {
            $failures[] = [
                'message' => 'deploy-drift: не видно ни одного успешного git fetch (.git/FETCH_HEAD отсутствует) — прод мог никогда не связаться с GitHub',
                'severity' => 'soft',
            ];
        } elseif ($lastFetch->lt(now()->subMinutes($fetchMaxAge))) {
            $failures[] = [
                'message' => sprintf(
                    'deploy-drift: последний УСПЕШНЫЙ git fetch был %s назад (порог %d мин) — прод перестал получать код, а сайт при этом жив. Проверь креденшал: `git -C /var/www/html ls-remote origin` (docs/deploy.md шаг 2)',
                    $lastFetch->diffForHumans(now(), true),
                    $fetchMaxAge,
                ),
                'severity' => 'soft',
            ];
        }

        $behind = $inspector->commitsBehind() ?? 0;
        $behindMaxAge = max(1, (int) config('cabinet_probe.deploy_drift_behind_max_age_minutes', 60));
        $originHead = $inspector->originHeadCommittedAt();

        if ($behind > 0 && $originHead !== null && $originHead->lt(now()->subMinutes($behindMaxAge))) {
            $failures[] = [
                'message' => sprintf(
                    'deploy-drift: прод отстал от origin/main на %d коммит(ов), самый свежий из них лежит уже %s (порог %d мин) — fetch работает, а деплой нет. Смотри storage/logs/auto_deploy.log и storage/auto_deploy.disabled',
                    $behind,
                    $originHead->diffForHumans(now(), true),
                    $behindMaxAge,
                ),
                'severity' => 'soft',
            ];
        }

        if ($failures === []) {
            $this->info(sprintf('Деплой не отстал (behind=%d, fetch %s назад).', $behind, $lastFetch?->diffForHumans(now(), true) ?? '—'));
        }

        return $failures;
    }

    private function probeServerGuards(): array
    {
        if (! config('cabinet_probe.check_server_guards', true) || ! config('server_guards.verify_enabled')) {
            return [];
        }

        try {
            $auditor = new ServerGuardsAuditor(
                GuardSpec::fromFile((string) config('server_guards.spec_path')),
                app(SystemInspector::class),
                (string) config('server_guards.template_root'),
            );
            $findings = $auditor->audit();
        } catch (Throwable $e) {
            // Проверять стало нечем — это тоже находка, но не повод падать пробе.
            return [['message' => 'guards: проверка не выполнилась — '.$e->getMessage(), 'severity' => 'soft']];
        }

        $failures = [];
        foreach ($findings as $finding) {
            if ($finding->severity === GuardFinding::INFO) {
                $this->comment('  ℹ guards: '.$finding->message);

                continue;
            }
            $failures[] = [
                'message' => 'guards/'.$finding->guard.': '.$finding->message,
                'severity' => $finding->severity === GuardFinding::CRITICAL ? 'critical' : 'soft',
            ];
        }

        if ($failures === []) {
            $this->info('Предохранители ОС на месте.');
        }

        return $failures;
    }

    /**
     * Синтетическая загрузка ДЗ (H37xx): «постоянно ломается подача ДЗ»
     * повторялась трижды (молчаливый 64MB-порог, OOM сборки PDF, дубли при
     * зависании), а ни одна проверка выше не трогала реальный upload-путь —
     * все surfaces GET. Пишет ОДИН тестовый файл через тот же
     * HomeworkService::recordSubmission(finalize: false), что и форма
     * студента, на выделенный sandbox-урок, и всегда удаляет его в finally —
     * идемпотентно на каждом прогоне. НЕ ловит php.ini/nginx
     * client_max_body_size (in-process вызов, не настоящий HTTP через
     * nginx/php-fpm) — см. config/cabinet_probe.php.
     *
     * @return list<array{message: string, severity: string}>
     */
    private function probeHomeworkUploadSynthetic(): array
    {
        if (! config('cabinet_probe.check_homework_upload', true)) {
            return [];
        }

        $slug = trim((string) config('cabinet_probe.homework_probe_course_slug', ''));
        $lessonId = (int) config('cabinet_probe.homework_probe_lesson_id', 0);
        if ($slug === '' || $lessonId <= 0) {
            $this->comment('CABINET_PROBE_HOMEWORK_COURSE/_LESSON_ID пусты — синтетическая загрузка ДЗ пропущена.');

            return [];
        }

        $student = Auth::user();
        if (! $student instanceof User) {
            return [['message' => 'homework-upload: нет auth-студента для пробы', 'severity' => 'critical']];
        }

        try {
            $course = Course::resolveBySlugOrFail($slug);
            $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);
        } catch (Throwable $e) {
            return [['message' => 'homework-upload: probe-курс/урок не найден ('.$slug.'/'.$lessonId.') — проверь CABINET_PROBE_HOMEWORK_*', 'severity' => 'soft']];
        }

        if (! $lesson->homeworkOpenFor($student)) {
            return [['message' => 'homework-upload: probe-урок закрыт для ДЗ — проверь homework_enabled/homework_closed_at на sandbox-уроке', 'severity' => 'soft']];
        }

        $disk = 'local';
        $extensions = (array) config('homework.allowed_extensions', ['txt']);
        $extension = in_array('txt', $extensions, true) ? 'txt' : (string) ($extensions[0] ?? 'txt');
        $relativePath = "homework/{$student->id}/{$lesson->id}/probe_".Str::random(8).'.'.$extension;
        $contents = 'cabinet:probe synthetic homework check '.now()->toIso8601String();

        try {
            Storage::disk($disk)->put($relativePath, $contents);
        } catch (Throwable $e) {
            return [['message' => 'homework-upload: диск '.$disk.' не пишется — '.$e->getMessage(), 'severity' => 'critical']];
        }

        if (! Storage::disk($disk)->exists($relativePath) || Storage::disk($disk)->size($relativePath) !== strlen($contents)) {
            Storage::disk($disk)->delete($relativePath);

            return [['message' => 'homework-upload: файл записан, но не читается обратно (диск '.$disk.')', 'severity' => 'critical']];
        }

        $failures = [];
        $submission = null;
        $comment = null;

        try {
            $submission = app(HomeworkService::class)->recordSubmission(
                $student,
                $lesson,
                'cabinet:probe synthetic check',
                [[
                    'disk' => $disk,
                    'path' => $relativePath,
                    'original_name' => 'probe.'.$extension,
                    'size' => strlen($contents),
                    'mime' => 'text/plain',
                ]],
                false,
            );

            $comment = $submission->comments()
                ->where('author_id', $student->id)
                ->where('type', HomeworkComment::TYPE_SUBMISSION)
                ->orderByDesc('id')
                ->with('files')
                ->first();

            if ($submission->status !== HomeworkSubmission::STATUS_DRAFT) {
                $failures[] = ['message' => 'homework-upload: сдача записалась не в draft (status='.$submission->status.')', 'severity' => 'critical'];
            }

            if (! $comment || $comment->files->isEmpty()) {
                $failures[] = ['message' => 'homework-upload: файл не привязался к сдаче в БД', 'severity' => 'critical'];
            }
        } catch (Throwable $e) {
            $failures[] = ['message' => 'homework-upload: запись сдачи упала — '.$e->getMessage(), 'severity' => 'critical'];
        } finally {
            // Sandbox-урок принадлежит только пробе — всегда откатываем до
            // пустого состояния, чтобы прогоны были идемпотентны и ничего не
            // накапливалось в БД/на диске.
            if ($comment) {
                foreach ($comment->files as $file) {
                    Storage::disk($file->disk)->delete($file->path);
                    $file->delete();
                }
                $comment->delete();
            } else {
                Storage::disk($disk)->delete($relativePath);
            }
            if ($submission && $submission->comments()->doesntExist()) {
                $submission->delete();
            }
        }

        if ($failures === []) {
            $this->info('ДЗ-загрузка (synthetic): файл сохранён и привязан.');
        }

        return $failures;
    }

    /**
     * Outbound TLS к платёжному эквайрингу «Точки» (инцидент 25–28-08-2026).
     *
     * Чего эта проверка стоит. «Точка» сменила отдаваемую цепочку на
     * Russian Trusted Root CA (Минцифры), которого не было в серверном
     * CA-бандле: каждый чекаут падал cURL error 60 четыре дня, пользователь
     * видел «Сервис оплаты временно недоступен», а все проверки были зелёные —
     * in-process surfaces ходят на localhost, guards смотрят в файлы и
     * systemd, outbound-TLS не смотрел никто.
     *
     * ЛЮБОЙ HTTP-ответ = TLS жив (неавторизованный 401/403/404 от API банка
     * нормален, тело не анализируется). Падает только СОЕДИНЕНИЕ — cURL 60
     * (сертификат), 7 (con refused), 28 (timeout) — тогда critical: оплаты
     * у пользователей недоступны, значит это HTTP-класс, а не host/ops:
     * Telegram сразу, Better Stack /fail, deploy --fail-on-critical блокируется.
     *
     * @return list<array{message: string, severity: string}>
     */
    private function probeOutboundPaymentTls(): array
    {
        if (! config('cabinet_probe.check_payment_tls', true)) {
            return [];
        }

        $url = (string) config('cabinet_probe.payment_probe_url', '');
        if ($url === '') {
            return [];
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?: $url);

        try {
            Http::timeout((int) config('cabinet_probe.timeout', 15))
                ->acceptJson()
                ->get($url);
        } catch (Throwable $e) {
            return [[
                'message' => 'payments: нет связи с '.$host.' — '.$e->getMessage().' (оплата у пользователей недоступна)',
                'severity' => 'critical',
            ]];
        }

        return [];
    }

    /**
     * Будущие занятия без ссылки-подключения (инцидент 02-09-2026, schedule
     * 1620: курс 401 + найденная тем же переписом мина курс 399 — серии
     * нового учебного года сгенерированы без ссылок, и в TG-чат ушло
     * напоминание «…по ссылке:» без самой ссылки). Кодовый guard теперь
     * молча НЕ отправляет напоминание без ссылки — эта проверка делает
     * пустоту громкой ДО занятия, пока у админа есть время её заполнить.
     *
     * Fallback-цепочка та же, что в zapisi:remind-classes / classes:post-group-link:
     * zoom_join_url → link → course.zoom_link. Soft: не outage, data-gap.
     *
     * @return list<array{message: string, severity: string}>
     */
    private function probeScheduleLinks(): array
    {
        if (! config('cabinet_probe.check_schedule_links', true)) {
            return [];
        }

        $horizon = max(1, (int) config('cabinet_probe.schedule_links_horizon_days', 14));

        $missing = Schedule::query()
            ->with(['group', 'course'])
            ->whereNull('zoom_join_url')
            ->whereNull('link')
            ->whereNotNull('group_id')
            ->whereBetween('start', [now(), now()->addDays($horizon)])
            ->orderBy('start')
            ->get()
            ->filter(fn (Schedule $s) => empty($s->group?->telegram_chat_id) === false
                && empty($s->course?->zoom_link));

        if ($missing->isEmpty()) {
            return [];
        }

        $byCourse = $missing->groupBy(fn (Schedule $s) => $s->course_id === null ? 'group:'.$s->group_id : 'course:'.$s->course_id);
        $lines = [];
        foreach ($byCourse as $rows) {
            /** @var Schedule $first */
            $first = $rows->first();
            $label = $first->course->title ?? ($first->group->name ?? '?');
            $nearest = $first->start->format('d-m H:i');
            $lines[] = sprintf('%s: %d занятие(й) без ссылки, ближайшее %s', $label, $rows->count(), $nearest);

            // Ссылку заполняет куратор курса (Настя/Иван), а не админ — зовём
            // их напрямую через общий curator-чат (дедуп 24h на курс внутри
            // нотификатора). --dry/--no-alert и не-прод не звонят.
            if ($first->course !== null && ! $this->option('dry') && ! $this->option('no-alert')
                && config('app.env') === 'production') {
                app(CuratorNotifier::class)->scheduleLinkMissing($first->course, $rows->count(), $nearest);
            }
        }

        return [[
            'message' => sprintf(
                'schedule-links: %d буд. занятие(й) с TG-чатом без ссылки (zoom_join_url/link/course.zoom_link пусты) — напоминание уйдёт без ссылки или не уйдёт вовсе: %s',
                $missing->count(),
                implode('; ', array_slice($lines, 0, 5)),
            ),
            'severity' => 'soft',
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $surfaces
     * @return list<array{message: string, severity: string}>
     */
    private function probeSurfacesList(array $surfaces, bool $authenticated): array
    {
        $failures = [];
        $markers = config('cabinet_probe.error_markers', []);

        foreach ($surfaces as $surface) {
            $label = (string) ($surface['label'] ?? $surface['name'] ?? $surface['uri'] ?? '?');
            $severity = (string) ($surface['severity'] ?? 'critical');
            $uri = $this->resolveUri($surface);
            if ($uri === null) {
                $failures[] = ['message' => "$label: маршрут не зарегистрирован", 'severity' => $severity];

                continue;
            }

            try {
                $response = $authenticated
                    ? $this->getAuthenticated($uri)
                    : $this->getGuest($uri);
            } catch (Throwable $e) {
                $failures[] = ['message' => "$label: exception ".$e->getMessage(), 'severity' => $severity];

                continue;
            }

            $status = $response->getStatusCode();
            if ($status >= 400) {
                $failures[] = ['message' => "$label: HTTP $status на $uri", 'severity' => $severity];

                continue;
            }

            $body = (string) $response->getContent();
            foreach ($markers as $marker) {
                if ($marker !== '' && str_contains($body, (string) $marker)) {
                    $failures[] = ['message' => "$label: маркер «{$marker}»", 'severity' => $severity];
                    break;
                }
            }

            if (! empty($surface['panel'])) {
                $final = $response->headers->get('Location') ?? '';
                if ($status === 302 && str_contains($final, 'login')) {
                    $failures[] = ['message' => "$label: редирект на login", 'severity' => $severity];
                }
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $surface
     */
    private function resolveUri(array $surface): ?string
    {
        if (! empty($surface['name'])) {
            try {
                return route((string) $surface['name'], [], false);
            } catch (Throwable) {
                return null;
            }
        }

        $uri = (string) ($surface['uri'] ?? '');

        return $uri !== '' ? $uri : null;
    }

    private function getGuest(string $uri): Response
    {
        $kernel = app(Kernel::class);
        $request = Request::create($uri, 'GET');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    private function getAuthenticated(string $uri): Response
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new \RuntimeException('нет auth user перед probe surface');
        }

        Auth::guard('web')->setUser($user);

        $kernel = app(Kernel::class);
        $request = Request::create($uri, 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(static fn () => Auth::guard('web')->user());

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        Auth::guard('web')->setUser($user);

        return $response;
    }

    /**
     * @param  list<array{message: string, severity: string}>  $failures
     */
    private function hasCritical(array $failures): bool
    {
        foreach ($failures as $f) {
            if (($f['severity'] ?? 'critical') === 'critical') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{message: string, severity: string}>  $failures
     */
    private function hasAuthFailure(array $failures, string $label): bool
    {
        foreach ($failures as $f) {
            if (str_starts_with($f['message'], $label.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{message: string, severity: string}>  $failures
     */
    private function recordHistory(bool $healthy, bool $criticalHealthy, int $durationMs, array $failures): void
    {
        try {
            $messages = array_map(fn ($f) => '['.($f['severity'] ?? '?').'] '.$f['message'], $failures);
            CabinetProbeRun::query()->create([
                'ran_at' => now(),
                'healthy' => $healthy,
                'critical' => ! $criticalHealthy,
                'duration_ms' => $durationMs,
                'failure_count' => count($failures),
                'failures' => $messages === [] ? null : $messages,
                'summary' => $healthy
                    ? 'ok'
                    : mb_substr(implode('; ', $messages), 0, 500),
            ]);

            $keep = max(50, (int) config('cabinet_probe.history_keep', 500));
            $cutoffId = CabinetProbeRun::query()->orderByDesc('id')->skip($keep)->value('id');
            if ($cutoffId) {
                CabinetProbeRun::query()->where('id', '<=', $cutoffId)->delete();
            }
        } catch (Throwable $e) {
            // Migration not applied yet — don't kill the probe.
            Log::warning('cabinet:probe history write failed', ['error' => $e->getMessage()]);
            $this->comment('history: не записано ('.$e->getMessage().')');
        }
    }

    /**
     * @param  list<string>  $failureMessages
     */
    private function reportToHealthchecks(bool $healthy, array $failureMessages): void
    {
        $url = trim((string) config('cabinet_probe.ping_url', ''));
        if ($url === '') {
            $this->comment('CABINET_PROBE_PING_URL пуст — healthchecks не пингуется (см. DEPLOY_QUEUE H1794).');

            return;
        }

        $target = $healthy ? $url : rtrim($url, '/').'/fail';
        $body = $healthy ? 'ok' : implode('; ', $failureMessages);

        if ($this->option('dry')) {
            $this->comment('--dry: healthchecks → '.$target);

            return;
        }

        try {
            $response = Http::timeout((int) config('cabinet_probe.timeout', 15))
                ->withBody($body, 'text/plain')
                ->post($target);

            if ($response->successful()) {
                $this->info('healthchecks: '.$target);
            } else {
                Log::warning('cabinet:probe healthchecks non-2xx', ['status' => $response->status()]);
            }
        } catch (Throwable $e) {
            Log::warning('cabinet:probe healthchecks unreachable', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  list<array{message: string, severity: string}>  $criticalFails
     * @param  list<array{message: string, severity: string}>  $softFails
     */
    private function reportToTelegram(bool $criticalHealthy, array $criticalFails, array $softFails): void
    {
        $token = (string) config('services.telegram.bot_token', '');
        $hasSoftWebhook = trim((string) config('cabinet_probe.soft_webhook_url', '')) !== '';
        if ($token === '' && ! $hasSoftWebhook) {
            $this->comment('TELEGRAM_BOT_TOKEN пуст — TG выключен.');

            return;
        }
        if ($token === '') {
            $this->comment('TELEGRAM_BOT_TOKEN пуст — soft-webhook only.');
        }

        $criticalIds = $this->parseChatIds(config('cabinet_probe.telegram_chat_id', ''));
        $softIds = $this->parseChatIds(config('cabinet_probe.telegram_soft_chat_id', ''));
        if ($softIds === []) {
            $softIds = $criticalIds;
        }

        $state = app(CabinetProbeAlertState::class);
        $force = (bool) $this->option('force-alert');
        $reminderHours = max(0, (int) config('cabinet_probe.telegram_soft_reminder_hours', 24));

        // Soft + host-guard-critical (tmpfs/backup): sticky, not SOS.
        // H1941/H2335 + H3197: same class silent until green or reminder.
        if ($criticalHealthy && $softFails !== []) {
            $fingerprint = SoftFailureFingerprint::hash($softFails);
            $lastSoftAt = $state->getTime(CabinetProbeAlertState::LAST_SOFT_ALERT_AT);
            $lastFp = $state->getString(CabinetProbeAlertState::LAST_SOFT_FP);
            $sameSet = is_string($lastFp) && $lastFp === $fingerprint;
            if (! $force && $sameSet && $lastSoftAt !== null) {
                if ($reminderHours === 0) {
                    $this->comment('TG soft-sticky: тот же soft/host-класс, без re-alert до зелёного (reminder=0)');

                    return;
                }
                $elapsedH = (int) now()->diffInHours($lastSoftAt, absolute: true);
                if ($elapsedH < $reminderHours) {
                    $this->comment('TG soft-sticky: ~'.($reminderHours - $elapsedH).' ч до reminder (тот же soft/host-класс)');

                    return;
                }
            }

            $scopeRaw = $this->softAlertScope($softFails);
            $scope = e($scopeRaw);
            $hostOnly = $this->softFailsAreHostGuards($softFails);
            $heading = $hostOnly
                ? "⚠️ <b>Кабинет: host/ops</b> ({$scope})"
                : "⚠️ <b>Кабинет: soft-сбой</b> ({$scope})";
            $lines = array_map(fn ($f) => '• '.e($f['message']), array_slice($softFails, 0, 8));
            $text = $heading."\n\n"
                .$this->telegramUrlBlock()."\n"
                .implode("\n", $lines)."\n\n"
                .$this->telegramRunbook();
            $sent = $token !== '' && $softIds !== []
                ? $this->sendTelegram($token, $softIds, $text)
                : false;

            $webhook = ['attempted' => false, 'ok' => false, 'detail' => 'skipped'];
            if (! $this->option('dry')) {
                $webhook = app(SoftAlertWebhookNotifier::class)->notify($scopeRaw, $fingerprint, $softFails);
                if ($webhook['attempted']) {
                    $this->comment('soft-webhook: '.$webhook['detail']);
                }
            }

            if ($sent || $webhook['ok']) {
                $state->put([
                    CabinetProbeAlertState::LAST_SOFT_ALERT_AT => now(),
                    CabinetProbeAlertState::LAST_SOFT_FP => $fingerprint,
                ]);
            }

            return;
        }

        if ($token === '') {
            return;
        }

        if ($criticalHealthy) {
            $wasHttpDown = $state->getBool(CabinetProbeAlertState::HTTP_DOWN);
            $state->forget(
                CabinetProbeAlertState::HTTP_DOWN,
                CabinetProbeAlertState::LAST_HTTP_ALERT_AT,
                CabinetProbeAlertState::LAST_HTTP_FP,
                CabinetProbeAlertState::LAST_SOFT_ALERT_AT,
                CabinetProbeAlertState::LAST_SOFT_FP,
            );
            if (! $wasHttpDown) {
                return;
            }
            $text = "✅ <b>Личный кабинет снова работает</b>\n\n"
                .$this->telegramUrlBlock()."\n"
                ."Проверка прошла: вход smoke-аккаунтов и ключевые страницы снова отвечают.\n\n"
                .$this->telegramEscalationHint([])."\n\n"
                .$this->telegramRunbook();
            $this->sendTelegram($token, $criticalIds, $text);

            return;
        }

        $fingerprint = SoftFailureFingerprint::hash($criticalFails);
        $lastFp = $state->getString(CabinetProbeAlertState::LAST_HTTP_FP);
        $lastAt = $state->getTime(CabinetProbeAlertState::LAST_HTTP_ALERT_AT);
        $sameSet = is_string($lastFp) && $lastFp === $fingerprint;
        if (! $force && $sameSet && $lastAt !== null) {
            if ($reminderHours === 0) {
                $this->comment('TG HTTP-sticky: тот же кабинет-класс, без re-alert до зелёного');

                return;
            }
            $elapsedH = (int) now()->diffInHours($lastAt, absolute: true);
            if ($elapsedH < $reminderHours) {
                $this->comment('TG HTTP-sticky: ~'.($reminderHours - $elapsedH).' ч до reminder');

                return;
            }
        }

        $lines = array_map(fn ($f) => '• '.e($f['message']), array_slice($criticalFails, 0, 8));
        if ($softFails !== []) {
            $lines[] = '— host/soft —';
            foreach (array_slice($softFails, 0, 4) as $f) {
                $lines[] = '• '.e($f['message']);
            }
        }

        $text = "🚨 <b>Личный кабинет не работает</b>\n\n"
            .$this->telegramUrlBlock()."\n"
            ."Что упало:\n".implode("\n", $lines)."\n\n"
            .$this->telegramEscalationHint($criticalFails)."\n\n"
            .$this->telegramRunbook();

        $sent = $this->sendTelegram($token, $criticalIds, $text);
        $state->put([CabinetProbeAlertState::HTTP_DOWN => true]);
        if ($sent) {
            $state->put([
                CabinetProbeAlertState::LAST_HTTP_ALERT_AT => now(),
                CabinetProbeAlertState::LAST_HTTP_FP => $fingerprint,
            ]);
        }
    }

    /**
     * @param  array{message?: string, severity?: string}  $failure
     */
    private function isHostGuardFailure(array $failure): bool
    {
        $m = (string) ($failure['message'] ?? '');

        return str_starts_with($m, 'guards/') || str_starts_with($m, 'guards:');
    }

    /**
     * @param  list<array{message: string, severity: string}>  $fails
     */
    private function softFailsAreHostGuards(array $fails): bool
    {
        if ($fails === []) {
            return false;
        }
        foreach ($fails as $f) {
            if (! $this->isHostGuardFailure($f)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $chatIds
     */
    private function sendTelegram(string $token, array $chatIds, string $text): bool
    {
        if ($chatIds === []) {
            $this->comment('TG chat_id пуст.');

            return false;
        }

        if ($this->option('dry')) {
            $this->comment('--dry: TG → '.implode(',', $chatIds));

            return false;
        }

        $any = false;
        foreach ($chatIds as $chatId) {
            try {
                $response = Http::timeout((int) config('cabinet_probe.timeout', 15))
                    ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                        'chat_id' => $chatId,
                        'text' => $text,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                if ($response->successful() && ($response->json('ok') ?? false)) {
                    $any = true;
                    $this->info('TG → '.$chatId);
                } else {
                    Log::warning('cabinet:probe tg fail', ['chat_id' => $chatId, 'body' => $response->body()]);
                }
            } catch (Throwable $e) {
                Log::warning('cabinet:probe tg error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            }
        }

        return $any;
    }

    /**
     * @return list<string>
     */
    private function parseChatIds(mixed $raw): array
    {
        if ($raw === null || $raw === false || $raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', trim((string) $raw)) ?: [];

        return array_values(array_filter(array_map('strval', $parts), static fn (string $id): bool => $id !== ''));
    }

    private function telegramUrlBlock(): string
    {
        return "Сам сайт: https://samskrte.ru\n"
            ."Вход: https://samskrte.ru/login\n"
            ."Кабинет: https://samskrte.ru/dvaram\n"
            ."Админка: https://samskrte.ru/admin\n"
            ."Витрина: https://samskrte.ru/online\n";
    }

    private function telegramRunbook(): string
    {
        $steps = config('cabinet_probe.runbook', []);
        if (! is_array($steps) || $steps === []) {
            return '<code>php artisan cabinet:probe</code>';
        }
        $lines = ['<b>Runbook</b>:'];
        foreach ($steps as $step) {
            $lines[] = '• <code>'.e((string) $step).'</code>';
        }

        return implode("\n", $lines);
    }

    /**
     * H2104: не пугать «только Артём» на app-level fuse (auto-deploy timeout
     * при живом HTTP). Артём — host/LXC down (SSH нет).
     *
     * @param  list<array{message?: string, severity?: string}>  $criticalFails
     */
    private function telegramEscalationHint(array $criticalFails): string
    {
        $onlyAutoDeploy = $criticalFails !== [];
        foreach ($criticalFails as $f) {
            $m = (string) ($f['message'] ?? '');
            if (! str_contains($m, 'auto-deploy') && ! str_contains($m, 'auto_deploy')) {
                $onlyAutoDeploy = false;
                break;
            }
        }

        if ($criticalFails === []) {
            // recovery: short neutral note
            return 'Если снова упадёт: smoke /login → SSH runbook. Host down (нет SSH) — Иван/Марцис → Артём (@t3t3r1n).';
        }

        if ($onlyAutoDeploy) {
            return "Это guards/auto-deploy (fuse), не «сервер мёртв».\n"
                .'Сайт часто 200: <code>cat storage/auto_deploy.disabled</code> → smoke → '
                ."после разбора <code>rm storage/auto_deploy.disabled</code>.\n"
                .'Артёма (@t3t3r1n) звать только если SSH не отвечает / хост мёртв.';
        }

        return 'Сначала SSH + runbook. Поднять VPS/контейнер может только Артём (@t3t3r1n) — '
            .'отвечает нечасто; звать только при отсутствии SSH / host-down.';
    }

    /**
     * Scope for soft TG title — derived from failure messages, not a fixed label.
     *
     * @param  list<array{message: string, severity: string}>  $softFails
     */
    private function softAlertScope(array $softFails): string
    {
        $guards = false;
        $hybrid = false;
        $other = false;
        foreach ($softFails as $f) {
            $m = (string) ($f['message'] ?? '');
            if (str_starts_with($m, 'guards/') || str_starts_with($m, 'guards:')) {
                $guards = true;
            } elseif (
                str_contains($m, 'hybrid ')
                || str_contains($m, 'hybrid /')
                || str_contains($m, '/library')
                || str_contains($m, '/progress')
                || str_contains($m, '/access')
            ) {
                $hybrid = true;
            } else {
                $other = true;
            }
        }

        $parts = [];
        if ($guards) {
            $parts[] = 'guards';
        }
        if ($hybrid) {
            $parts[] = 'hybrid';
        }
        if ($other) {
            $parts[] = $parts === [] ? 'опциональные проверки' : 'прочее';
        }

        return $parts === [] ? 'некритичные проверки' : implode(' / ', $parts);
    }
}
