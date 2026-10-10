<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\Course;
use App\Models\CourseQuiz;
use App\Models\CourseQuizQuestion;
use App\Models\Group;
use App\Models\PromoCode;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Награды за итоговый тест мини-курса: зачёт финального квиза выдаёт
 * персональный промокод на курс грамматики (детерминированный код,
 * без дублей при пересдаче) и показывает приглашение на ближайшее
 * занятие «напевного» курса.
 */
class FinalQuizRewardsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Course $course;

    private CourseQuiz $finalQuiz;

    private Course $grammarCourse;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mini_courses.grammar_course_id' => 0]);
        config(['mini_courses.trial_course_id' => 0]);

        $this->user = User::factory()->create();
        $group = Group::create(['name' => 'G-final-rewards']);
        $this->user->groups()->attach($group);

        $this->course = Course::factory()->create(['is_active' => true, 'slug' => 'final-rewards']);
        $this->course->groups()->attach($group);

        // Итоговый тест живёт вне этапов: block_number больше любого блока курса.
        $this->finalQuiz = CourseQuiz::create([
            'course_id' => $this->course->id,
            'block_number' => 7,
            'title' => 'Итоговый тест курса',
            'pass_score' => 60,
            'is_active' => true,
        ]);
        $this->makeQuestion('Вопрос финала?', ['Верно', 'Неверно'], 0);
        $this->makeQuestion('Ещё вопрос?', ['Да', 'Нет'], 0);

        $this->grammarCourse = Course::factory()->create(['is_active' => true]);
        config(['mini_courses.grammar_course_id' => $this->grammarCourse->id]);
    }

    private function makeQuestion(string $prompt, array $options, int $correct): void
    {
        CourseQuizQuestion::create([
            'course_quiz_id' => $this->finalQuiz->id,
            'question' => $prompt,
            'options' => $options,
            'correct_option' => $correct,
            'explanation' => null,
            'sort_order' => $this->finalQuiz->questions()->count(),
        ]);
    }

    private function submitAllCorrect(): void
    {
        $answers = [];
        foreach ($this->finalQuiz->questions as $question) {
            $answers[$question->id] = $question->correct_option;
        }

        $this->actingAs($this->user)
            ->post(route('student.course.quiz.submit', [$this->course->slug, $this->finalQuiz->block_number]), [
                'answers' => $answers,
            ])
            ->assertOk()
            ->assertSee('Зачёт');
    }

    /** @test */
    public function passing_final_quiz_issues_personal_promo(): void
    {
        $this->submitAllCorrect();

        $promo = PromoCode::where('course_id', $this->grammarCourse->id)->first();
        $this->assertNotNull($promo);
        $this->assertSame('percent', $promo->type);
        $this->assertEquals(50.0, (float) $promo->value);
        $this->assertSame('PREP50-'.$this->user->id, $promo->code);
        $this->assertSame(1, $promo->usage_limit);
        $this->assertTrue($promo->is_active);
    }

    /** @test */
    public function retake_does_not_duplicate_promo(): void
    {
        $this->submitAllCorrect();
        $this->submitAllCorrect();

        $this->assertSame(1, PromoCode::where('course_id', $this->grammarCourse->id)->count());
    }

    /** @test */
    public function result_page_shows_promo_code(): void
    {
        $this->submitAllCorrect();

        $this->actingAs($this->user)
            ->get(route('student.course.quiz', [$this->course->slug, $this->finalQuiz->block_number]))
            ->assertOk()
            ->assertSee('PREP50-'.$this->user->id)
            ->assertSee($this->grammarCourse->title);
    }

    /** @test */
    public function stage_quiz_pass_issues_no_promo(): void
    {
        $stageQuiz = CourseQuiz::create([
            'course_id' => $this->course->id,
            'block_number' => 1,
            'title' => 'Квиз этапа 1',
            'pass_score' => 60,
            'is_active' => true,
        ]);
        $q = CourseQuizQuestion::create([
            'course_quiz_id' => $stageQuiz->id,
            'question' => 'Этаповый вопрос?',
            'options' => ['Верно', 'Неверно'],
            'correct_option' => 0,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->user)
            ->post(route('student.course.quiz.submit', [$this->course->slug, 1]), ['answers' => [$q->id => 0]])
            ->assertOk();

        $this->assertSame(0, PromoCode::count());
    }

    /** @test */
    public function result_page_invites_to_nearest_upcoming_trial_event(): void
    {
        $trialCourse = Course::factory()->create(['is_active' => true, 'title' => 'Напевный санскрит — Гита 3 часть']);
        Schedule::create([
            'title' => 'Напевный санскрит — живое занятие',
            'course_id' => $trialCourse->id,
            'group_id' => Group::create(['name' => 'G-trial-sched'])->id,
            'start' => now()->addDays(3),
            'end' => now()->addDays(3)->addHours(2),
        ]);
        config(['mini_courses.trial_course_id' => $trialCourse->id]);

        $this->submitAllCorrect();

        $this->actingAs($this->user)
            ->get(route('student.course.quiz', [$this->course->slug, $this->finalQuiz->block_number]))
            ->assertOk()
            ->assertSee('Пробное занятие по напевному санскриту')
            ->assertSee($trialCourse->title);
    }

    /** @test */
    public function no_invite_without_upcoming_event(): void
    {
        config(['mini_courses.trial_course_id' => 0]);

        $this->submitAllCorrect();

        $this->actingAs($this->user)
            ->get(route('student.course.quiz', [$this->course->slug, $this->finalQuiz->block_number]))
            ->assertOk()
            ->assertDontSee('Пробное занятие по напевному санскриту');
    }
}
