<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Payment;
use Illuminate\Http\Request;
use App\Services\CertificateService;
use Illuminate\Support\Carbon;
use App\Services\CourseMaterialsArchiver;

// --- ИМПОРТЫ ДОЛЖНЫ БЫТЬ ЗДЕСЬ, В САМОМ ВЕРХУ ---
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

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
        if (!$courseId) {
            return [];
        }

        // 2. Ищем оплаченные тарифы строго по ID КУРСА, а не лендинга
        return Payment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('status', 'paid')
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
            ->where(function($query) use ($groupIds) {
                $query->whereIn('group_id', $groupIds)
                      ->orWhereNull('group_id');
            })
            ->where('start', '>=', now())
            ->orderBy('start', 'asc')
            ->get();

        $groupedEvents = $upcomingEvents->groupBy(function ($event) {
            if ($event->start->isToday()) return 'Сегодня';
            if ($event->start->isTomorrow()) return 'Завтра';
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
    $courses = Course::where('is_active', true)
        ->whereHas('groups', function ($query) use ($userGroupIds) {
            $query->whereIn('groups.id', $userGroupIds);
        })
        ->get();

    $certificates = $user->certificates()
        ->with('course')
        ->orderBy('created_at', 'desc')
        ->get();

    return view('student.dashboard', compact('courses', 'certificates'));
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

    $lessons = $course->lessons()->orderBy('created_at', 'asc')->get();
    $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $slug);

    return view('student.course', compact('course', 'lessons', 'unlockedTariffs'));
}

    /**
     * Просмотр конкретного урока (Плеер + Навигация)
     */
    public function showLesson($courseSlug, $lessonId)
    {
        $user = auth()->user();
        $course = Course::where('slug', $courseSlug)->firstOrFail();
        $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

        // --- БЛОК ЗАЩИТЫ ДОСТУПА С УЧЕТОМ КОНКРЕТНОГО КУРСА ---
        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $courseSlug);
        $requiredTariff = 'block_' . $lesson->block_number;

        // Открытые уроки/вебинары доступны любому залогиненному без покупки
        $isFreeLesson = (bool) $lesson->is_free;

        // Проверяем наличие 'full' или конкретного 'block_X'
        if (!$isFreeLesson && !in_array('full', $unlockedTariffs) && !in_array($requiredTariff, $unlockedTariffs)) {
            return redirect()->route('student.course', $course->slug)
                ->with('error', 'Этот урок доступен в Блоке ' . $lesson->block_number . '. Для просмотра необходимо оплатить доступ.');
        }
        // ==========================================
    // --- ТРЕКИНГ ПРОСМОТРА УРОКА (async) ---
    // ==========================================
    // Не dispatchим для админов (они просматривают уроки для проверки, это не учебная активность)
    if (!$user->is_admin) {
        \App\Jobs\TrackLessonViewJob::dispatch(
            userId:           $user->id,
            lessonId:         $lesson->id,
            courseId:         $course->id,
            laravelSessionId: request()->session()->getId(),
            url:              request()->fullUrl(),
            ipAddress:        request()->ip(),
        );
    }
    // ==========================================
        
        $lessons = $course->lessons()->orderBy('created_at', 'asc')->get();

        $currentNote = null;
        $completedLesson = $user->completedLessons()->where('lesson_id', $lesson->id)->first();
        if ($completedLesson) {
            $currentNote = $completedLesson->pivot->notes;
        }

        $youtubeId = $this->parseVideoId($lesson->youtube_url, 'youtube');
        $rutubeId  = $this->parseVideoId($lesson->rutube_url, 'rutube');

        // ==========================================
        // --- БЛОК ОБРАБОТКИ JSON ТРАНСКРИПЦИИ ---
        // ==========================================
        $transcriptSentences = [];

        if (!empty($lesson->transcript_file)) {
            $cacheKey = 'lesson_transcript_' . $lesson->id;
            
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
                        $currentSentence .= $word . ' ';
                        $sentenceEnd = $wordData['end'] ?? $sentenceStart;
                        
                        // Если слово кончается точкой/вопросом/восклицанием — закрываем предложение
                        if (preg_match('/[.!?]$/', trim($word))) {
                            $seconds = (int)$sentenceStart;
                            $formattedTime = $seconds >= 3600 ? gmdate("H:i:s", $seconds) : gmdate("i:s", $seconds);
                            
                            $sentences[] = [
                                'formatted_time' => $formattedTime,
                                'start'          => (float)$sentenceStart,
                                'end'            => (float)$sentenceEnd,
                                'text'           => trim($currentSentence),
                                'safe_text'      => mb_strtolower(htmlspecialchars(trim($currentSentence), ENT_QUOTES))
                            ];
                            $currentSentence = '';
                        }
                    }

                    // САМАЯ ВАЖНАЯ ЧАСТЬ: СОХРАНЯЕМ "ХВОСТ" ЛЕКЦИИ (даже если он без точки)
                    if (!empty(trim($currentSentence))) {
                        $seconds = (int)$sentenceStart;
                        $formattedTime = $seconds >= 3600 ? gmdate("H:i:s", $seconds) : gmdate("i:s", $seconds);
                        
                        $sentences[] = [
                            'formatted_time' => $formattedTime,
                            'start'          => (float)$sentenceStart,
                            'end'            => (float)$sentenceEnd,
                            'text'           => trim($currentSentence),
                            'safe_text'      => mb_strtolower(htmlspecialchars(trim($currentSentence), ENT_QUOTES))
                        ];
                    }
                }
                
                return $sentences;
            });
        }
        // ==========================================
        // ==========================================

        // Передаем переменную $transcriptSentences в шаблон
        return view('student.lesson', compact('course', 'lesson', 'lessons', 'youtubeId', 'rutubeId', 'currentNote', 'unlockedTariffs', 'transcriptSentences'));
    }

    /**
     * Отметить урок как пройденный
     */
    public function completeLesson($courseSlug, $lessonId)
    {
        $user = auth()->user();
        
        if (!$user->completedLessons()->where('lesson_id', $lessonId)->exists()) {
            $user->completedLessons()->attach($lessonId, [
                'is_completed' => true,
            ]);
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

        $existing = $user->completedLessons()->where('lesson_id', $lessonId)->first();

        if ($existing) {
            $user->completedLessons()->updateExistingPivot($lessonId, ['notes' => $request->input('notes')]);
        } else {
            $user->completedLessons()->attach($lessonId, [
                'is_completed' => false,
                'notes' => $request->input('notes')
            ]);
        }

        return redirect()->back()->with('success', 'Заметка сохранена');
    }
    
    /**
 * Скачать архив со всеми материалами курса.
 * Учитывает права доступа студента (оплаченные блоки).
 */
public function downloadCourseMaterials(string $slug, CourseMaterialsArchiver $archiver)
{
    $user = auth()->user();
    $userGroupIds = $user->groups->pluck('id');

    // Проверяем, что курс доступен этому студенту (он в нужной группе)
    $course = Course::where('slug', $slug)
        ->where('is_visible', true)
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
        return $pdf->download('Certificate_' . $certificate->course->id . '.pdf');
    }

    /**
     * === ВСПОМОГАТЕЛЬНЫЙ МЕТОД: Парсер ссылок видео ===
     */
    private function parseVideoId(?string $url, string $platform): ?string
    {
        if (!$url) return null;

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
                if (!empty($query)) {
                    return $cleanId . '?' . $query;
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