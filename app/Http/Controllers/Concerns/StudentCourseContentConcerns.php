<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\TrackLessonViewJob;
use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\LessonView;
use App\Models\Schedule;
use App\Services\AccessDiagnosticsService;
use App\Services\Activity\CabinetTelemetry;
use App\Services\Cabinet\GrammarLadder;
use App\Services\Cabinet\RecoveryStateResolver;
use App\Services\CourseContinuationBanner;
use App\Services\HindiAttachmentDrills;
use App\Services\HindiProgrammePlaylist;
use App\Services\HindiTranscriptDrills;
use App\Services\Membership\ClubEntitlement;
use App\Services\Membership\RecordingAccessPolicy;
use App\Services\Prana\PranaService;
use App\Support\KinescopePilot;
use App\Support\TranscriptParser;
use Illuminate\Http\Request;

/**
 * Domain-scoped concern for StudentController — extracted by H4978 split
 * (recovered under H5245 PR-sweep). Implementations are main's current bodies.
 */
trait StudentCourseContentConcerns
{
    /**
     * Просмотр содержания курса (список уроков)
     */
    public function showCourse($slug)
    {
        $user = auth()->user();
        $userGroupIds = $user->groups->pluck('id');

        // БЫЛО: where('is_visible', true)
        // СТАЛО: where('is_active', true)
        $course = Course::resolveBySlugOrFail($slug);
        abort_unless($course->is_active, 404);

        // H2644: клубный член видит курс полки, не состоя ни в одной его группе —
        // право даёт членство, а не запись на поток.
        $clubCovers = app(ClubEntitlement::class)->coversCourse($user, $course);

        abort_unless(
            $clubCovers || $course->groups()->whereIn('groups.id', $userGroupIds)->exists(),
            404
        );

        // Групповой фильтр уроков (курс, разнесённый на два потока) для клубного
        // члена неприменим: он не в потоке, а смотрит запись — иначе список
        // оказался бы пустым при открытом курсе.
        $lessonsQuery = $course->lessons();
        if (! $clubCovers) {
            $lessonsQuery->forUserGroups($user);
        }
        $lessons = $lessonsQuery->orderBy('sort_order')->orderBy('created_at')->get();
        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $course->slug);
        $grantedLessonIds = LessonAccessGrant::userGrantedLessonIds($user, (int) $course->id);

        // H2333: “where is lesson 1?” when this shell continues another course.
        $continuationBanner = app(CourseContinuationBanner::class)->for($course, $user);

        // H2386: per-locked-lesson access findings (flag OFF → empty map, views no-op).
        $accessSelfService = (bool) config('features.access_self_service', false);
        $accessFindingsByLessonId = [];
        if ($accessSelfService) {
            $diag = app(AccessDiagnosticsService::class);
            foreach ($lessons as $lesson) {
                $open = $lesson->is_free
                    || $lesson->is_preview
                    || in_array($lesson->id, $grantedLessonIds, true)
                    || $lesson->isUnlockedBy($unlockedTariffs);
                if (! $open) {
                    $accessFindingsByLessonId[$lesson->id] = $diag->forLesson(
                        $user,
                        $lesson,
                        $course,
                        $unlockedTariffs,
                    );
                }
            }
        }

        if (config('features.cabinet_hybrid')) {
            $recovery = app(RecoveryStateResolver::class)->resolve($user);
            $completedLessonIds = $user->completedLessons->pluck('id')->all();
            // R29.8: block start/end landmarks — orientation only, never pay deadlines.
            $landmarks = app(GrammarLadder::class)->landmarksForCourse($course);

            return view('student.hybrid.course', [
                'course' => $course,
                'lessons' => $lessons,
                'unlockedTariffs' => $unlockedTariffs,
                'grantedLessonIds' => $grantedLessonIds,
                'completedLessonIds' => $completedLessonIds,
                'recovery' => $recovery,
                'suppressOffers' => $recovery->suppressOffers(),
                'landmarks' => $landmarks,
                'continuationBanner' => $continuationBanner,
                'accessSelfService' => $accessSelfService,
                'accessFindingsByLessonId' => $accessFindingsByLessonId,
            ]);
        }

        return view('student.course', compact(
            'course',
            'lessons',
            'unlockedTariffs',
            'grantedLessonIds',
            'continuationBanner',
            'accessSelfService',
            'accessFindingsByLessonId',
        ));
    }

    /**
     * Просмотр конкретного урока (Плеер + Навигация)
     */
    public function showLesson($courseSlug, $lessonId, PranaService $prana)
    {
        $user = auth()->user();
        $course = Course::resolveBySlugOrFail($courseSlug);
        $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);
        $courseSlug = $course->slug;

        $access = $this->resolveLessonAccessGate($user, $course, $lesson, $courseSlug);
        if ($access['redirect'] !== null) {
            return $access['redirect'];
        }
        $hasLessonGrant = $access['hasLessonGrant'];
        $clubLesson = $access['clubLesson'];
        $unlockedTariffs = $access['unlockedTariffs'];
        $recordingAccess = $access['recordingAccess'];

        $this->trackLessonView($user, $course, $lesson, $prana);

        ['lessons' => $lessons, 'currentNote' => $currentNote]
            = $this->buildLessonNavigationAndNote($user, $course, $lesson);

        ['hasRecognizedVideo' => $hasRecognizedVideo, 'videoResumeEnabled' => $videoResumeEnabled,
            'kinescopeEmbedUrl' => $kinescopeEmbedUrl, 'resumePosition' => $resumePosition,
            'resumeDuration' => $resumeDuration, 'upcomingSession' => $upcomingSession]
            = $this->buildLessonVideoData($user, $course, $lesson);

        // ==========================================
        // --- БЛОК ОБРАБОТКИ JSON ТРАНСКРИПЦИИ ---
        // ==========================================
        // Разбор JSON-расшифровки в предложения с таймкодами вынесен в TranscriptParser
        // (переиспользуется блоком лендинга «Стенограмма вебинара»). Кэш — внутри сервиса.
        $transcriptSentences = TranscriptParser::sentencesFromStoredFile($lesson->transcript_file);

        ['homeworkOpen' => $homeworkOpen, 'homeworkSubmission' => $homeworkSubmission]
            = $this->buildLessonHomeworkData($user, $lesson);

        ['hindiDrillsUrl' => $hindiDrillsUrl, 'lywUrl' => $lywUrl]
            = $this->buildLessonHindiAndLywUrls($user, $course, $lesson);

        // Передаем переменную $transcriptSentences в шаблон
        // H4396: youtubeId/rutubeId больше не передаются в вью — сырые ID не
        // должны попадать в HTML, плеер грузит серверные ворота записи.
        return view('student.lesson', compact('course', 'lesson', 'lessons', 'currentNote', 'unlockedTariffs', 'transcriptSentences', 'homeworkOpen', 'homeworkSubmission', 'upcomingSession', 'videoResumeEnabled', 'resumePosition', 'resumeDuration', 'kinescopeEmbedUrl', 'hindiDrillsUrl', 'recordingAccess', 'lywUrl'));
    }

    /**
     * Отметить урок как пройденный
     */
    public function completeLesson($courseSlug, $lessonId, PranaService $prana)
    {
        $user = auth()->user();
        $this->ensureLessonAccessible($user, $courseSlug, $lessonId);

        // Уже пройден? Проверяем по реально завершённой строке pivot,
        // а не по любой записи (которую могла создать saveNote с is_completed=false).
        $alreadyCompleted = $user->completedLessons()->where('lesson_id', $lessonId)->exists();

        if (! $alreadyCompleted) {
            // Если строка pivot уже есть (от saveNote) — апдейтим её,
            // иначе attach. Иначе создадим дубль pivot-строки (на таблице
            // lesson_user нет уникального индекса по user_id+lesson_id).
            $hasPivotRow = $user->lessonProgress()->where('lesson_id', $lessonId)->exists();
            if ($hasPivotRow) {
                $user->lessonProgress()->updateExistingPivot($lessonId, [
                    'is_completed' => true,
                ]);
            } else {
                $user->lessonProgress()->attach($lessonId, [
                    'is_completed' => true,
                ]);
            }

            $course = Course::resolveBySlugOrFail($courseSlug);
            $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

            // Начисление за урок (идемпотентно по lesson_id).
            $prana->award($user, 'lesson_complete', $lesson);

            // Baseline-телеметрия ремейка (H962, спека §4): ручное «усвоено».
            // Только при НОВОМ завершении — повторный клик события не пишет.
            app(CabinetTelemetry::class)->emit(
                user: $user,
                event: ActivityEvent::LESSON_MARK_MASTERED,
                data: ['course_id' => $course->id, 'lesson_id' => $lesson->id],
                request: request(),
            );

            // Метрика lesson_mark_mastered (MG 18-09): flash внутри той же
            // ветки «новое завершение» — маркер на redirect-целе стреляет
            // reachGoal ровно один раз (reader: partials/cabinet-metrika).
            session()->flash('metrika_goal', 'lesson_mark_mastered');

            // Если этот урок закрыл весь курс — начисляем бонус за курс
            // (тоже идемпотентно по course_id). Гейтим по членству в группах
            // курса — иначе на курсах, где все уроки is_free=true, любой
            // залогиненный юзер мог бы прокликать course_complete (+500🪷).
            $userInCourseGroups = $course->groups()
                ->whereIn('groups.id', $user->groups->pluck('id'))
                ->exists();

            if ($userInCourseGroups) {
                // Считаем только уроки, видимые этому студенту по его группе
                // (для курсов из 2 потоков total у каждой группы свой).
                $totalLessons = $course->lessons()->forUserGroups($user)->count();
                $completedLessons = $user->completedLessons()
                    ->where('lessons.course_id', $course->id)
                    ->count();

                if ($totalLessons > 0 && $completedLessons >= $totalLessons) {
                    $prana->award($user, 'course_complete', $course);
                }
            }
        }

        return redirect()->back()->with('success', 'Урок пройден!');
    }

    /**
     * Сохранение заметки
     */
    public function saveNote(Request $request, $courseSlug, $lessonId)
    {
        $user = auth()->user();
        $request->validate(['notes' => 'nullable|string|max:5000']);
        $this->ensureLessonAccessible($user, $courseSlug, $lessonId);

        // Через lessonProgress (без фильтра по is_completed) — иначе на пользователе,
        // у которого уже есть строка pivot с is_completed=false (от прошлой заметки),
        // мы создавали бы дубль вместо апдейта.
        $existing = $user->lessonProgress()->where('lesson_id', $lessonId)->first();

        if ($existing) {
            $user->lessonProgress()->updateExistingPivot($lessonId, ['notes' => $request->input('notes')]);
        } else {
            $user->lessonProgress()->attach($lessonId, [
                'is_completed' => false,
                'notes' => $request->input('notes'),
            ]);
        }

        return redirect()->back()->with('success', 'Заметка сохранена');
    }

    /**
     * Проверяет, что урок принадлежит курсу из URL и доступен пользователю
     * (свободный или оплачен через full/block_X). Иначе — abort(403/404).
     * Защищает completeLesson/saveNote от IDOR на уроки чужих курсов.
     */
    private function ensureLessonAccessible($user, string $courseSlug, $lessonId): void
    {
        $course = Course::resolveBySlugOrFail($courseSlug);
        $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

        if (! $lesson->isVisibleToGroupsOf($user)) {
            abort(403, 'Этот урок относится к другой группе курса.');
        }

        if ($lesson->is_free) {
            return;
        }

        $unlocked = $this->getUserUnlockedTariffs($user->id, $course->slug);

        if (! $lesson->isUnlockedBy($unlocked)) {
            abort(403, 'Нет доступа к этому уроку.');
        }
    }

    /**
     * Скачать архив со всеми материалами курса.
     * Учитывает права доступа студента (оплаченные блоки).
     */
    public function downloadCourseMaterials(string $slug, CourseMaterialsArchiver $archiver)
    {
        $user = auth()->user();
        $userGroupIds = $user->groups->pluck('id');

        // Проверяем, что курс доступен этому студенту (он в нужной группе).
        // Используем is_active (а не is_visible) — это видимость в ЛК, согласованно с dashboard/showCourse.
        $course = Course::resolveBySlugOrFail($slug);
        abort_unless($course->is_active, 404);
        abort_unless(
            $course->groups()->whereIn('groups.id', $userGroupIds)->exists(),
            404
        );

        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $course->slug);

        if (empty($unlockedTariffs)) {
            return back()->with('error', 'У вас нет оплаченных блоков для этого курса.');
        }

        try {
            return $archiver->buildForUser($course, $user, $unlockedTariffs);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Библиотека курса — реестр ссылок на литературу (фаза 1).
     *
     * Доступ: курс активен + студент в группе курса. Тарифы НЕ гейтят страницу
     * целиком — иначе общая библиография курса исчезала бы в промежутках между
     * оплатами блоков. Материал, привязанный к уроку, наследует замок этого
     * урока по той же формуле, что и showCourse().
     */
    public function courseLibrary(string $slug)
    {
        abort_unless((bool) config('features.course_library', false), 404);

        $user = auth()->user();
        $userGroupIds = $user->groups->pluck('id');

        $course = Course::resolveBySlugOrFail($slug);
        abort_unless($course->is_active, 404);
        abort_unless(
            $course->groups()->whereIn('groups.id', $userGroupIds)->exists(),
            404
        );

        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $course->slug);
        $grantedLessonIds = LessonAccessGrant::userGrantedLessonIds($user, (int) $course->id);

        $materials = CourseMaterial::query()
            ->with('lesson')
            ->where('course_id', $course->id)
            ->visible()
            ->shelfOrder()
            ->get()
            ->filter(function (CourseMaterial $material) use ($unlockedTariffs, $grantedLessonIds): bool {
                // Материал курса (без урока) виден всем, у кого есть курс.
                if ($material->lesson_id === null) {
                    return true;
                }
                $lesson = $material->lesson;
                // Урок удалён/недоступен — материал не показываем.
                if ($lesson === null) {
                    return false;
                }

                return $lesson->is_free
                    || $lesson->is_preview
                    || in_array($lesson->id, $grantedLessonIds, true)
                    || $lesson->isUnlockedBy($unlockedTariffs);
            })
            ->values();

        // Полка курса отдельно от полок уроков — так студент видит общую
        // библиографию, не пролистывая её сквозь уроки.
        $courseWide = $materials->whereNull('lesson_id')->values();
        $byLesson = $materials->whereNotNull('lesson_id')->groupBy('lesson_id');

        return view('student.course-library', [
            'course' => $course,
            'courseWide' => $courseWide,
            'byLesson' => $byLesson,
        ]);
    }

    private function buildLessonHindiAndLywUrls($user, Course $course, Lesson $lesson): array
    {
        $hindiDrillsUrl = null;
        $hindiDrills = app(HindiTranscriptDrills::class);
        $hindiAttachments = app(HindiAttachmentDrills::class);
        $hindiOk = $hindiDrills->isHindiLesson($lesson);
        $hindiTeacherPreview = app(HindiProgrammePlaylist::class)->teachesHindi($user);
        $hasTranscriptItems = ($hindiDrills->enabled() || $hindiTeacherPreview) && $hindiOk && $hindiDrills->hasItems($lesson, $hindiTeacherPreview);
        $hasAttachmentPath = ($hindiAttachments->enabled() || $hindiTeacherPreview) && $hindiOk && $hindiAttachments->hasPracticePath($lesson);
        if ($hasTranscriptItems || $hasAttachmentPath) {
            $hindiDrillsUrl = route('student.lesson.drills', [$course->slug, $lesson->id]);
        }

        // H3521: вкладка «Learn Your Way» — только при включённом LYW_ENABLED
        // (default OFF: переменная остаётся null, разметки в шаблоне нет).
        $lywUrl = null;
        if (config('lyw.enabled')) {
            $lywUrl = route('student.lesson.lessonpack', [$course->slug, $lesson->id]);
        }

        return compact('hindiDrillsUrl', 'lywUrl');
    }

    private function buildLessonHomeworkData($user, Lesson $lesson): array
    {
        // Открыт ли приём работ ИМЕННО ДЛЯ ЭТОГО студента (H1764). Считается
        // один раз здесь и передаётся в шаблон: витрина и серверный гейт
        // обязаны отвечать на этот вопрос одинаково.
        $homeworkOpen = $lesson->homeworkOpenFor($user);

        // Домашняя работа этого студента по уроку (если приём открыт)
        $homeworkSubmission = null;
        if ($homeworkOpen) {
            $homeworkSubmission = $user->homeworkSubmissions()
                ->where('lesson_id', $lesson->id)
                ->with(['comments.files', 'comments.author'])
                ->first();
        }

        return compact('homeworkOpen', 'homeworkSubmission');
    }

    private function buildLessonNavigationAndNote($user, Course $course, Lesson $lesson): array
    {
        $lessons = $course->lessons()->forUserGroups($user)->orderBy('sort_order')->orderBy('created_at')->get();

        $currentNote = null;
        // Заметка может быть сохранена ДО отметки «пройдено», поэтому читаем
        // через lessonProgress (без фильтра по is_completed).
        $progressRow = $user->lessonProgress()->where('lesson_id', $lesson->id)->first();
        if ($progressRow) {
            $currentNote = $progressRow->pivot->notes;
        }

        return compact('lessons', 'currentNote');
    }

    private function buildLessonVideoData($user, Course $course, Lesson $lesson): array
    {
        // H4396: сырые ID в HTML не уходят (серверные ворота записи), но
        // «запись ещё не залита» определяется так же — по распознанным ссылкам.
        $hasRecognizedVideo = self::parseVideoId($lesson->youtube_url, 'youtube') !== null
            || self::parseVideoId($lesson->rutube_url, 'rutube') !== null;

        // In-video resume (H1450, W2). Пока флаг video_resume выключен, JS ничего
        // не шлёт и баннер «продолжить» не показывается — эти переменные лежат
        // в вью мёртвым грузом, ровно как до H1450.
        $videoResumeEnabled = (bool) config('features.video_resume');
        // H1451 W3 (true-redo): multi-field resolve — video_url preferred, then
        // youtube_url / rutube_url if staff pasted a kinescope.io link there.
        $kinescopeEmbedUrl = KinescopePilot::embedForLesson(
            $lesson,
            $course->id ?? null
        );
        $resumePosition = null;
        $resumeDuration = null;
        if ($videoResumeEnabled) {
            $lessonView = LessonView::where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->first();

            if ($lessonView && ! $lessonView->is_completed && (int) $lessonView->last_position_seconds > 0) {
                $resumePosition = (int) $lessonView->last_position_seconds;
                $resumeDuration = $lessonView->video_duration_seconds ? (int) $lessonView->video_duration_seconds : null;
            }
        }

        // Запись ещё не залита (живое занятие только состоится — например, пробное).
        // Подтягиваем событие расписания на эту дату, чтобы показать «Состоится … +
        // Подключиться к Zoom» вместо пустого плеера. n8n позже дозальёт видео.
        $upcomingSession = null;
        if (! $hasRecognizedVideo && empty($kinescopeEmbedUrl) && empty($lesson->video_url) && $lesson->lesson_date) {
            $upcomingSession = Schedule::query()
                ->where('course_id', $course->id)
                ->where('group_id', $lesson->group_id)
                ->whereDate('start', $lesson->lesson_date)
                ->orderBy('start')
                ->first();
        }

        return compact('hasRecognizedVideo', 'videoResumeEnabled', 'kinescopeEmbedUrl', 'resumePosition', 'resumeDuration', 'upcomingSession');
    }

    private function resolveLessonAccessGate($user, Course $course, Lesson $lesson, string $courseSlug): array
    {
        // Разовый доступ к конкретному уроку (например, оплаченное пробное занятие) —
        // обход и блок/full гейта, и группового: явный grant на этот урок главнее.
        $hasLessonGrant = LessonAccessGrant::userCanWatch($user, $lesson);

        // H2644: клубное покрытие курса — такое же основание видеть урок, как
        // персональный грант: членство не привязано к потоку.
        $club = app(ClubEntitlement::class);
        $clubCovers = $club->coversCourse($user, $course);
        $clubLesson = $club->coversLesson($user, $course, $lesson);

        // Урок другой группы курса (курс разнесён на 2 потока) — не показываем,
        // если только нет персонального гранта именно на этот урок.
        if (! $hasLessonGrant && ! $clubCovers && ! $clubLesson && ! $lesson->isVisibleToGroupsOf($user)) {
            return [
                'redirect' => redirect()->route('student.course', $course->slug)
                    ->with('error', 'Этот урок относится к другой группе курса.'),
            ];
        }

        // --- БЛОК ЗАЩИТЫ ДОСТУПА С УЧЕТОМ КОНКРЕТНОГО КУРСА ---
        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $courseSlug);

        // Открытые уроки/вебинары доступны любому залогиненному без покупки
        $isFreeLesson = (bool) $lesson->is_free;

        if (! $isFreeLesson && ! $hasLessonGrant && ! $clubLesson && ! $lesson->isUnlockedBy($unlockedTariffs)) {
            return [
                'redirect' => redirect()->route('student.course', $course->slug)
                    ->with('error', 'Этот урок доступен в Блоке '.$lesson->block_number.'. Для просмотра необходимо оплатить доступ.'),
            ];
        }

        // H2744: this decision gates only the recording payload. The lesson
        // route, texts, homework, schedule and live links remain purchase-based.
        $recordingAccess = app(RecordingAccessPolicy::class)->decide(
            $user,
            $course,
            $lesson,
            $hasLessonGrant,
            'web_recording',
        );

        return [
            'redirect' => null,
            'hasLessonGrant' => $hasLessonGrant,
            'clubLesson' => $clubLesson,
            'unlockedTariffs' => $unlockedTariffs,
            'recordingAccess' => $recordingAccess,
        ];
    }

    private function trackLessonView($user, Course $course, Lesson $lesson, PranaService $prana): void
    {
        // ==========================================
        // --- ТРЕКИНГ ПРОСМОТРА УРОКА (async) ---
        // ==========================================
        // Не dispatchим для админов (они просматривают уроки для проверки, это не учебная активность)
        if (! $user->is_admin) {
            TrackLessonViewJob::dispatch(
                userId: $user->id,
                lessonId: $lesson->id,
                courseId: $course->id,
                laravelSessionId: request()->session()->getId(),
                url: request()->fullUrl(),
                ipAddress: request()->ip(),
            );

            // Прана за просмотр открытого урока/вебинара (раз на урок благодаря
            // уникальному индексу по reason+source).
            if ($lesson->is_free) {
                $prana->award($user, 'open_lesson_view', $lesson);
            }
        }
        // ==========================================
    }
}
