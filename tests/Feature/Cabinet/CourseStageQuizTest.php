<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\Course;
use App\Models\CourseQuiz;
use App\Models\CourseQuizAttempt;
use App\Models\CourseQuizQuestion;
use App\Models\Group;
use App\Models\User;
use App\Support\CourseStageQuiz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Квизы этапов курса (мини-курсы): доступ как у страницы курса, показ
 * с детерминированным перемешиванием, проверка ответов и попытки.
 */
class CourseStageQuizTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Course $course;

    private CourseQuiz $quiz;

    /** @var list<CourseQuizQuestion> */
    private array $questions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $group = Group::create(['name' => 'G-prep-quiz']);
        $this->user->groups()->attach($group);

        $this->course = Course::factory()->create([
            'is_active' => true,
            'is_visible' => true,
            'title' => 'Подготовительная группа',
            'slug' => 'prep-quiz-test',
        ]);
        $this->course->groups()->attach($group);

        $this->quiz = CourseQuiz::create([
            'course_id' => $this->course->id,
            'block_number' => 1,
            'title' => 'Квиз этапа 1',
            'description' => 'Проверка первого этапа',
            'pass_score' => 60,
            'is_active' => true,
        ]);

        $this->questions = [
            $this->makeQuestion('Сколько уроков в мини-курсе?', ['Пять', 'Три', 'Десять'], 0, 'Уроков пять.'),
            $this->makeQuestion('Как называется письмо санскрита?', ['Кириллица', 'Деванагари', 'Латиница'], 1, 'Письмо — деванагари.'),
        ];
    }

    private function makeQuestion(string $prompt, array $options, int $correct, ?string $why = null): CourseQuizQuestion
    {
        return CourseQuizQuestion::create([
            'course_quiz_id' => $this->quiz->id,
            'question' => $prompt,
            'options' => $options,
            'correct_option' => $correct,
            'explanation' => $why,
            'sort_order' => $this->quiz->questions()->count(),
        ]);
    }

    /** @test */
    public function quiz_page_404s_for_user_outside_course_groups(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('student.course.quiz', [$this->course->slug, 1]))
            ->assertNotFound();
    }

    /** @test */
    public function quiz_page_renders_for_group_member(): void
    {
        $this->actingAs($this->user)
            ->get(route('student.course.quiz', [$this->course->slug, 1]))
            ->assertOk()
            ->assertSee('Квиз этапа 1')
            ->assertSee('Сколько уроков в мини-курсе?')
            ->assertSee('Деванагари');
    }

    /** @test */
    public function missing_or_inactive_quiz_404s(): void
    {
        $this->actingAs($this->user)
            ->get(route('student.course.quiz', [$this->course->slug, 9]))
            ->assertNotFound();

        $this->quiz->update(['is_active' => false]);

        $this->actingAs($this->user)
            ->get(route('student.course.quiz', [$this->course->slug, 1]))
            ->assertNotFound();
    }

    /** @test */
    public function all_correct_answers_create_passed_attempt(): void
    {
        $answers = [];
        foreach ($this->questions as $question) {
            $answers[$question->id] = $question->correct_option;
        }

        $response = $this->actingAs($this->user)
            ->post(route('student.course.quiz.submit', [$this->course->slug, 1]), ['answers' => $answers]);

        $response->assertOk()->assertSee('Зачёт');

        $attempt = CourseQuizAttempt::where('user_id', $this->user->id)
            ->where('course_quiz_id', $this->quiz->id)
            ->first();

        $this->assertNotNull($attempt);
        $this->assertTrue($attempt->passed);
        $this->assertSame(2, $attempt->score);
        $this->assertSame(2, $attempt->total);
    }

    /** @test */
    public function below_threshold_answers_fail_and_explanations_show(): void
    {
        $answers = [];
        foreach ($this->questions as $i => $question) {
            // 0 из 2 — заведомый провал при пороге 60%.
            $answers[$question->id] = ($question->correct_option + 1) % count($question->options);
        }
        $this->assertNotSame($answers[$this->questions[0]->id], $this->questions[0]->correct_option);

        $this->actingAs($this->user)
            ->post(route('student.course.quiz.submit', [$this->course->slug, 1]), ['answers' => $answers])
            ->assertOk()
            ->assertSee('Не то')
            ->assertSee('Правильный ответ');

        $attempt = CourseQuizAttempt::where('user_id', $this->user->id)->first();
        $this->assertNotNull($attempt);
        $this->assertFalse($attempt->passed);
    }

    /** @test */
    public function answers_are_required(): void
    {
        $this->actingAs($this->user)
            ->from(route('student.course.quiz', [$this->course->slug, 1]))
            ->post(route('student.course.quiz.submit', [$this->course->slug, 1]), ['answers' => []])
            ->assertRedirect(route('student.course.quiz', [$this->course->slug, 1]));

        $this->assertSame(0, CourseQuizAttempt::count());
    }

    /** @test */
    public function course_page_lists_quizzes_with_best_attempt(): void
    {
        $this->actingAs($this->user)
            ->get(route('student.course', $this->course->slug))
            ->assertOk()
            ->assertSee('Проверь себя')
            ->assertSee('Квиз этапа 1')
            ->assertSee('Квиз этапа — проверить знания');

        // Прошли на зачёт → на карточке статуса виден результат.
        CourseQuizAttempt::create([
            'user_id' => $this->user->id,
            'course_quiz_id' => $this->quiz->id,
            'score' => 2,
            'total' => 2,
            'passed' => true,
            'answers' => [],
        ]);

        $this->actingAs($this->user)
            ->get(route('student.course', $this->course->slug))
            ->assertOk()
            ->assertSee('Пройден: 2/2');
    }

    /** @test */
    public function hybrid_course_page_lists_quizzes_too(): void
    {
        config(['features.cabinet_hybrid' => true]);

        $this->actingAs($this->user)
            ->get(route('student.course', $this->course->slug))
            ->assertOk()
            ->assertSee('Проверь себя')
            ->assertSee('Квиз этапа 1');
    }

    /** @test */
    public function grade_matches_mixed_answers(): void
    {
        $graded = CourseStageQuiz::grade($this->quiz, [
            $this->questions[0]->id => $this->questions[0]->correct_option,
            $this->questions[1]->id => ($this->questions[1]->correct_option + 1) % 3,
        ]);

        $this->assertSame(1, $graded['score']);
        $this->assertSame(2, $graded['total']);
        $this->assertFalse($graded['passed']); // 50% < 60%

        $wrong = collect($graded['details'])->firstWhere('id', $this->questions[1]->id);
        $this->assertFalse($wrong['ok']);
        $this->assertStringContainsString('Деванагари', (string) $wrong['why']);
    }

    /** @test */
    public function option_shuffle_is_deterministic_permutation(): void
    {
        $question = $this->questions[0];

        $first = CourseStageQuiz::shuffledOptions($question, 77);
        $again = CourseStageQuiz::shuffledOptions($question, 77);

        $this->assertSame($first, $again);
        $this->assertEqualsCanonicalizing($question->options, array_values($first));
        // Ключи — исходные индексы вариантов (проверка не зависит от перемешивания).
        $this->assertEqualsCanonicalizing([0, 1, 2], array_keys($first));
    }
}
