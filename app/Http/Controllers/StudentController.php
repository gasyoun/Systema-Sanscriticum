<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Models\Schedule;
use App\Services\CertificateService;
use App\Services\CourseMaterialsArchiver;
use App\Services\Prana\PranaService;
use App\Services\Prana\PranaSettings;
use Illuminate\Http\Request;
// --- ИМПОРТЫ ДОЛЖНЫ БЫТЬ ЗДЕСЬ, В САМОМ ВЕРХУ ---
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class StudentController extends Controller
{
    /**
     * === ВСПОМОГАТЕЛЬНЫЙ МЕТОД: Получение купленных тарифов ===
     * Железобетонный метод проверки доступов строго по ID КУРСА
     */
    private function getUserUnlockedTariffs($userId, $courseSlug): array
    {
        // 1. Находим ID курса по его slug
        $courseId = Course::where('slug', $courseSlug)->value('id');

        // Если курс не найден, возвращаем пустой массив (нет доступов)
        if (! $courseId) {
            return [];
        }

        // 2. Ищем оплаченные тарифы строго по ID КУРСА, а не лендинга.
        //    Учитываем оба статуса оплаты ('paid' и 'success') — иначе урок
        //    остаётся закрытым для success-платежей, хотя группа уже выдана.
        return Payment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->paid()
            ->pluck('tariff')
            ->toArray();
    }

    /**
     * Страница расписания (Timeline)
     */
    public function calendar()
    {
        $user = auth()->user();
        $groupIds = $user->groups->pluck('id');

        $upcomingEvents = Schedule::with(['course', 'group'])
            ->where(function ($query) use ($groupIds) {
                $query->whereIn('group_id', $groupIds)
                    ->orWhereNull('group_id');
            })
            ->where('start', '>=', now())
            ->orderBy('start', 'asc')
            ->get();

        $groupedEvents = $upcomingEvents->groupBy(function ($event) {
            if ($event->start->isToday()) {
                return 'Сегодня';
            }
            if ($event->start->isTomorrow()) {
                return 'Завтра';
            }

            return $event->start->translatedFormat('d F, l');
        });

        return view('student.calendar', compact('groupedEvents'));
    }

    /**
     * Главная панель (Мои курсы)
     */
    public function dashboard()
    {
        $user = auth()->user();
        $userGroupIds = $user->groups->pluck('id');

        // БЫЛО: where('is_visible', true) — ломало доступ при скрытии с витрины
        // СТАЛО: фильтруем по is_active (видимость в ЛК)
        // lessons:id,course_id — eager-load для прогресс-бара в карточках курсов (без этого N+1).
        $courses = Course::where('is_active', true)
            ->whereHas('groups', function ($query) use ($userGroupIds) {
                $query->whereIn('groups.id', $userGroupIds);
            })
            ->with(['lessons:id,course_id'])
            ->get();

        $certificates = $user->certificates()
            ->with('course')
            ->orderBy('created_at', 'desc')
            ->get();

        $pranaTransactions = $user->pranaTransactions()->limit(30)->get();
        // Сами числа наград админ редактирует в админке → читаем через PranaSettings,
        // иначе панель «Как заработать прану» расходится с тем, что реально начисляет
        // PranaService::award. Лейблы (reasons) не редактируются — оставляем config.
        $pranaRewards = PranaSettings::allRewards();
        $pranaReasons = config('prana.reasons', []);

        $debts = app(\App\Services\StudentDebtsService::class)->forUser($user);
        $debtsByCourseId = $debts->keyBy('course_id');

        // Отдельно открытые уроки (например, оплаченное пробное занятие): курсы, к
        // которым нет полного доступа по группам, но есть персональный grant на урок.
        $trialLessons = LessonAccessGrant::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereNotIn('course_id', $courses->pluck('id')->all())
            ->with(['lesson:id,course_id,title,lesson_date', 'course:id,slug,title'])
            ->get()
            ->filter(fn (LessonAccessGrant $g) => $g->lesson && $g->course)
            ->values();

        return view('student.dashboard', compact(
            'courses',
            'certificates',
            'pranaTransactions',
            'pranaRewards',
            'pranaReasons',
            'debts',
            'debtsByCourseId',
            'trialLessons',
        ));
    }

    /**
     * Просмотр содержания курса (список уроков)
     */
    public function showCourse($slug)
    {
        $user = auth()->user();
        $userGroupIds = $user->groups->pluck('id');

        // БЫЛО: where('is_visible', true)
        // СТАЛО: where('is_active', true)
        $course = Course::where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('groups', function ($query) use ($userGroupIds) {
                $query->whereIn('groups.id', $userGroupIds);
            })
            ->firstOrFail();

        $lessons = $course->lessons()->forUserGroups($user)->orderBy('sort_order')->orderBy('created_at')->get();
        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $slug);
        $grantedLessonIds = LessonAccessGrant::userGrantedLessonIds($user, (int) $course->id);

        return view('student.course', compact('course', 'lessons', 'unlockedTariffs', 'grantedLessonIds'));
    }

    /**
     * Просмотр конкретного урока (Плеер + Навигация)
     */
    public function showLesson($courseSlug, $lessonId, PranaService $prana)
    {
        $user = auth()->user();
        $course = Course::where('slug', $courseSlug)->firstOrFail();
        $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

        // Разовый доступ к конкретному уроку (например, оплаченное пробное занятие) —
        // обход и блок/full гейта, и группового: явный grant на этот урок главнее.
        $hasLessonGrant = LessonAccessGrant::userCanWatch($user, $lesson);

        // Урок другой группы курса (курс разнесён на 2 потока) — не показываем,
        // если только нет персонального гранта именно на этот урок.
        if (! $hasLessonGrant && ! $lesson->isVisibleToGroupsOf($user)) {
            return redirect()->route('student.course', $course->slug)
                ->with('error', 'Этот урок относится к другой группе курса.');
        }

        // --- БЛОК ЗАЩИТЫ ДОСТУПА С УЧЕТОМ КОНКРЕТНОГО КУРСА ---
        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $courseSlug);

        // Открытые уроки/вебинары доступны любому залогиненному без покупки
        $isFreeLesson = (bool) $lesson->is_free;

        if (! $isFreeLesson && ! $hasLessonGrant && ! $lesson->isUnlockedBy($unlockedTariffs)) {
            return redirect()->route('student.course', $course->slug)
                ->with('error', 'Этот урок доступен в Блоке '.$lesson->block_number.'. Для просмотра необходимо оплатить доступ.');
        }
        // ==========================================
        // --- ТРЕКИНГ ПРОСМОТРА УРОКА (async) ---
        // ==========================================
        // Не dispatchим для админов (они просматривают уроки для проверки, это не учебная активность)
        if (! $user->is_admin) {
            \App\Jobs\TrackLessonViewJob::dispatch(
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

        $lessons = $course->lessons()->forUserGroups($user)->orderBy('sort_order')->orderBy('created_at')->get();

        $currentNote = null;
        // Заметка может быть сохранена ДО отметки «пройдено», поэтому читаем
        // через lessonProgress (без фильтра по is_completed).
        $progressRow = $user->lessonProgress()->where('lesson_id', $lesson->id)->first();
        if ($progressRow) {
            $currentNote = $progressRow->pivot->notes;
        }

        $youtubeId = $this->parseVideoId($lesson->youtube_url, 'youtube');
        $rutubeId = $this->parseVideoId($lesson->rutube_url, 'rutube');

        // Запись ещё не залита (живое занятие только состоится — например, пробное).
        // Подтягиваем событие расписания на эту дату, чтобы показать «Состоится … +
        // Подключиться к Zoom» вместо пустого плеера. n8n позже дозальёт видео.
        $upcomingSession = null;
        if (empty($youtubeId) && empty($rutubeId) && empty($lesson->video_url) && $lesson->lesson_date) {
            $upcomingSession = \App\Models\Schedule::query()
                ->where('course_id', $course->id)
                ->where('group_id', $lesson->group_id)
                ->whereDate('start', $lesson->lesson_date)
                ->orderBy('start')
                ->first();
        }

        // ==========================================
        // --- БЛОК ОБРАБОТКИ JSON ТРАНСКРИПЦИИ ---
        // ==========================================
        $transcriptSentences = [];

        if (! empty($lesson->transcript_file)) {
            $cacheKey = 'lesson_transcript_'.$lesson->id;

            $transcriptSentences = Cache::rememberForever($cacheKey, function () use ($lesson) {
                $sentences = [];

                if (Storage::disk('public')->exists($lesson->transcript_file)) {
                    $jsonContent = Storage::disk('public')->get($lesson->transcript_file);
                    $data = json_decode($jsonContent, true);

                    // БЕРЕМ ИМЕННО СЛОВА (WORDS), ТАК КАК ОНИ НА 100% ПОЛНЫЕ
                    $words = $data['results']['channels'][0]['alternatives'][0]['words'] ?? [];
                    $currentSentence = '';
                    $sentenceStart = 0;
                    $sentenceEnd = 0;

                    foreach ($words as $wordData) {
                        if (empty($currentSentence)) {
                            $sentenceStart = $wordData['start'] ?? 0;
                        }

                        $word = $wordData['punctuated_word'] ?? $wordData['word'] ?? '';
                        $currentSentence .= $word.' ';
                        $sentenceEnd = $wordData['end'] ?? $sentenceStart;

                        // Если слово кончается точкой/вопросом/восклицанием — закрываем предложение
                        if (preg_match('/[.!?]$/', trim($word))) {
                            $seconds = (int) $sentenceStart;
                            $formattedTime = $seconds >= 3600 ? gmdate('H:i:s', $seconds) : gmdate('i:s', $seconds);

                            $sentences[] = [
                                'formatted_time' => $formattedTime,
                                'start' => (float) $sentenceStart,
                                'end' => (float) $sentenceEnd,
                                'text' => trim($currentSentence),
                                'safe_text' => mb_strtolower(htmlspecialchars(trim($currentSentence), ENT_QUOTES)),
                            ];
                            $currentSentence = '';
                        }
                    }

                    // САМАЯ ВАЖНАЯ ЧАСТЬ: СОХРАНЯЕМ "ХВОСТ" ЛЕКЦИИ (даже если он без точки)
                    if (! empty(trim($currentSentence))) {
                        $seconds = (int) $sentenceStart;
                        $formattedTime = $seconds >= 3600 ? gmdate('H:i:s', $seconds) : gmdate('i:s', $seconds);

                        $sentences[] = [
                            'formatted_time' => $formattedTime,
                            'start' => (float) $sentenceStart,
                            'end' => (float) $sentenceEnd,
                            'text' => trim($currentSentence),
                            'safe_text' => mb_strtolower(htmlspecialchars(trim($currentSentence), ENT_QUOTES)),
                        ];
                    }
                }

                return $sentences;
            });
        }
        // ==========================================
        // ==========================================

        // Домашняя работа этого студента по уроку (если задание включено)
        $homeworkSubmission = null;
        if ($lesson->homework_enabled) {
            $homeworkSubmission = $user->homeworkSubmissions()
                ->where('lesson_id', $lesson->id)
                ->with(['comments.files', 'comments.author'])
                ->first();
        }

        // Передаем переменную $transcriptSentences в шаблон
        return view('student.lesson', compact('course', 'lesson', 'lessons', 'youtubeId', 'rutubeId', 'currentNote', 'unlockedTariffs', 'transcriptSentences', 'homeworkSubmission', 'upcomingSession'));
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

            $course = Course::where('slug', $courseSlug)->firstOrFail();
            $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

            // Начисление за урок (идемпотентно по lesson_id).
            $prana->award($user, 'lesson_complete', $lesson);

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
        $course = Course::where('slug', $courseSlug)->firstOrFail();
        $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

        if (! $lesson->isVisibleToGroupsOf($user)) {
            abort(403, 'Этот урок относится к другой группе курса.');
        }

        if ($lesson->is_free) {
            return;
        }

        $unlocked = $this->getUserUnlockedTariffs($user->id, $courseSlug);

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
        $course = Course::where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('groups', function ($query) use ($userGroupIds) {
                $query->whereIn('groups.id', $userGroupIds);
            })
            ->firstOrFail();

        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $slug);

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
     * Скачивание сертификата
     */
    public function downloadCertificate($id, CertificateService $service)
    {
        $certificate = auth()->user()->certificates()->with('course')->findOrFail($id);
        $pdf = $service->generatePdf($certificate);

        return $pdf->download('Certificate_'.$certificate->course->id.'.pdf');
    }

    /**
     * Скачивание сертификата картинкой (JPEG).
     */
    public function downloadCertificateImage($id, CertificateService $service)
    {
        $certificate = auth()->user()->certificates()->with('course')->findOrFail($id);

        try {
            $jpeg = $service->generateJpegBytes($certificate);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response()->streamDownload(
            fn () => print $jpeg,
            'Certificate_'.$certificate->course->id.'.jpg',
            ['Content-Type' => 'image/jpeg'],
        );
    }

    /**
     * === ВСПОМОГАТЕЛЬНЫЙ МЕТОД: Парсер ссылок видео ===
     */
    private function parseVideoId(?string $url, string $platform): ?string
    {
        if (! $url) {
            return null;
        }

        if ($platform === 'youtube') {
            preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches);

            return $matches[1] ?? null;
        }

        if ($platform === 'rutube') {
            $parsed = parse_url($url);
            $path = $parsed['path'] ?? '';
            $query = $parsed['query'] ?? '';

            if (preg_match('/([a-zA-Z0-9]{32})/', $path, $matches)) {
                $cleanId = $matches[1];
                if (! empty($query)) {
                    return $cleanId.'?'.$query;
                }

                return $cleanId;
            }
        }

        return null;
    }

    /**
     * Раздел «Открытые уроки / вебинары» — доступен любому залогиненному студенту.
     * Показывает все уроки с is_free=true (независимо от покупок и групп).
     */
    public function openLessons()
    {
        $lessons = Lesson::free()
            ->where('is_published', true)
            ->with('course:id,title,slug')
            ->orderByDesc('lesson_date')
            ->orderByDesc('id')
            ->get();

        return view('student.open-lessons', compact('lessons'));
    }

    public function messages()
    {
        $user = auth()->user();

        // Добавили круглые скобки () и явно указали таблицу, чтобы избежать конфликтов!
        $userGroupIds = $user->groups()->pluck('groups.id')->toArray();

        $messages = \App\Models\Announcement::where('is_published', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(function ($announcement) use ($userGroupIds) {
                if (empty($announcement->target_groups)) {
                    return true;
                }

                return count(array_intersect($announcement->target_groups, $userGroupIds)) > 0;
            });

        return view('student.messages', compact('messages'));
    }
}
