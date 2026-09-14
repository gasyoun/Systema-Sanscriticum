<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\LessonPackRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * H3521 — страница «Learn Your Way» за default-OFF флагом LYW_ENABLED.
 *
 * Доступ зеркалит student.lesson.drills (auth + course.canonical), но сам
 * доступ не расширяет: гейт — тот же логин; контент-пак читается с диска.
 * Флаг выключен => 404 до любых чтений; пак отсутствует/сломан =>
 * 404 с логом. Ответы квиза студенту не отдаются никогда.
 */
class LessonPackController extends Controller
{
    public function __construct(private readonly LessonPackRepository $packs) {}

    public function show(Request $request, string $slug, int $lessonId): View
    {
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((bool) config('lyw.enabled', false), 404);

        $course = Course::resolveBySlugOrFail($slug);
        $lesson = Lesson::query()
            ->where('course_id', $course->id)
            ->findOrFail($lessonId);

        [$level, $interest] = $this->resolveProfile($request);
        $zan = (int) config('lyw.default_zan', 1);

        $pack = $this->packs->load($zan, $level, $interest);
        if ($pack === null) {
            Log::warning('lyw.pack.unresolvable', [
                'course' => $course->slug,
                'lesson' => $lesson->id,
                'zan' => $zan,
                'level' => $level,
                'interest' => $interest,
            ]);
            abort(404);
        }

        return view('student.lesson-learn', [
            'course' => $course,
            'lesson' => $lesson,
            'manifest' => $pack['manifest'],
            'text' => $pack['text'],
            'mindmap' => $pack['mindmap'],
            'profileLevel' => $level,
            'profileInterest' => $interest,
            'backUrl' => route('student.lesson', [$course->slug, $lesson->id]),
        ]);
    }

    /**
     * Профиль волны 1 — из query с дефолтом «база»; словарь валидирует.
     * Уровень из конфига курса и предпочтения студента — wave 2.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveProfile(Request $request): array
    {
        $levels = (array) config('lyw.levels', ['base']);
        $interests = (array) config('lyw.interests', ['base']);

        $level = (string) $request->query('level', '');
        $interest = (string) $request->query('interest', '');

        return [
            in_array($level, $levels, true) ? $level : (string) config('lyw.default_level', 'base'),
            in_array($interest, $interests, true) ? $interest : (string) config('lyw.default_interest', 'base'),
        ];
    }
}
