<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseQuiz;
use App\Models\CourseQuizAttempt;
use App\Services\Membership\ClubEntitlement;
use App\Support\CourseFinalRewards;
use App\Support\CourseStageQuiz;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Квиз этапа курса: интерактивная проверка после прохождения этапа мини-курса.
 * Доступ — как у страницы курса: активный курс + группа (или клубное покрытие).
 *
 * Зачёт итогового теста (block_number вне этапов — «Финал») выдаёт награды:
 * персональный промокод на курс грамматики + приглашение на ближайшее
 * занятие «напевного» курса (CourseFinalRewards).
 */
class CourseStageQuizController extends Controller
{
    public function show(Request $request, string $slug, int $block): View
    {
        $user = $request->user();
        [$course, $quiz] = $this->resolveAccessibleQuiz($user, $slug, $block);

        $bestAttempt = $quiz->bestAttemptFor($user);

        return view('student.course-stage-quiz', [
            'course' => $course,
            'quiz' => $quiz,
            'questions' => CourseStageQuiz::questionsForDisplay($quiz, (int) $user->id),
            'result' => null,
            'answers' => [],
            'bestAttempt' => $bestAttempt,
            'rewards' => $this->rewardsFor($course, $quiz, $user, $bestAttempt?->passed ?? false),
        ]);
    }

    public function submit(Request $request, string $slug, int $block): View|RedirectResponse
    {
        $user = $request->user();
        [$course, $quiz] = $this->resolveAccessibleQuiz($user, $slug, $block);

        $rules = [];
        foreach ($quiz->questions as $question) {
            $rules['answers.'.$question->id] = 'required|integer';
        }
        $data = $request->validate($rules, ['required' => 'Отметьте вариант.']);

        $graded = CourseStageQuiz::grade($quiz, $data['answers']);

        CourseQuizAttempt::create([
            'user_id' => $user->id,
            'course_quiz_id' => $quiz->id,
            'score' => $graded['score'],
            'total' => $graded['total'],
            'passed' => $graded['passed'],
            'answers' => $data['answers'],
        ]);

        return view('student.course-stage-quiz', [
            'course' => $course,
            'quiz' => $quiz,
            'questions' => CourseStageQuiz::questionsForDisplay($quiz, (int) $user->id),
            'result' => $graded,
            'answers' => $data['answers'],
            'bestAttempt' => $quiz->bestAttemptFor($user),
            'rewards' => $this->rewardsFor($course, $quiz, $user, $graded['passed']),
        ]);
    }

    /**
     * Награды финала: промокод создаётся только при ЗАЧЁТЕ итогового теста
     * (повторная сдача/просмотр новой попытки код не плодят — детерминированный
     * код PREP50-{user}). Приглашение — ближайшее будущее занятие курса,
     * если студент ещё не записан на него.
     */
    private function rewardsFor(Course $course, CourseQuiz $quiz, $user, bool $passed): ?array
    {
        $isFinal = $quiz->block_number > (int) $course->blocks->max('number') && $quiz->block_number > 1;

        if (! $isFinal || ! $passed) {
            return null;
        }

        $promo = CourseFinalRewards::promoCodeFor($user);
        $trialEvent = CourseFinalRewards::nearestTrialEvent();

        return [
            'promo_code' => CourseFinalRewards::promoDisplayCode($user),
            'promo_expires_at' => $promo->expires_at,
            'grammar_course' => CourseFinalRewards::grammarCourse(),
            'trial_event' => $trialEvent,
            'trial_course' => $trialEvent?->course,
            'invite' => $trialEvent !== null && CourseFinalRewards::shouldInvite($user),
        ];
    }

    /**
     * Курс по слагу + активный квиз этапа, с проверкой доступа как в showCourse:
     * активный курс и членство в группе (или клубное покрытие H2644).
     *
     * @return array{0: Course, 1: CourseQuiz}
     */
    private function resolveAccessibleQuiz($user, string $slug, int $block): array
    {
        $course = Course::resolveBySlugOrFail($slug);
        abort_unless($course->is_active, 404);

        $clubCovers = app(ClubEntitlement::class)->coversCourse($user, $course);
        abort_unless(
            $clubCovers || $course->groups()->whereIn('groups.id', $user->groups->pluck('id'))->exists(),
            404,
        );

        /** @var CourseQuiz|null $quiz */
        $quiz = $course->quizzes()
            ->where('block_number', $block)
            ->where('is_active', true)
            ->first();
        abort_if($quiz === null, 404);

        return [$course, $quiz];
    }
}
