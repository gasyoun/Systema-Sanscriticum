<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\MiniCourseProgress;
use App\Models\Course;
use App\Models\CourseQuiz;
use App\Models\CourseQuizAttempt;
use App\Models\CourseQuizQuestion;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Страница «Прогресс мини-курса»: воронка студентов по этапам — уроки,
 * квизы этапов, финал, домашки, промокод и его погашение, источник.
 */
class MiniCourseProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    private Course $grammarCourse;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mini_courses.slug' => 'progress-test-course']);
        config(['mini_courses.grammar_course_id' => 0]);
        config(['mini_courses.trial_course_id' => 0]);

        $this->admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
        $this->grammarCourse = Course::factory()->create(['title' => 'Грамматика санскрита с нуля — набор 2026']);

        $this->course = Course::factory()->create(['is_active' => true, 'slug' => 'progress-test-course']);
        $group = Group::create(['name' => 'G-progress']);
        $this->course->groups()->attach($group);

        foreach ([1, 2] as $block) {
            CourseQuiz::create([
                'course_id' => $this->course->id,
                'block_number' => $block,
                'title' => 'Квиз этапа '.$block,
                'pass_score' => 60,
                'is_active' => true,
            ]);
        }

        Lesson::factory()->count(3)->create(['course_id' => $this->course->id]);
    }

    private function enroll(User $user): void
    {
        $user->groups()->attach($this->course->groups()->first());
        $user->courses()->attach($this->course->id, ['status' => 'Записался', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @test */
    public function page_renders_for_admin_and_lists_enrolled_students(): void
    {
        $student = User::factory()->create(['name' => 'Воронкова Анна']);
        $this->enroll($student);

        Livewire::actingAs($this->admin)
            ->test(MiniCourseProgress::class)
            ->assertSuccessful()
            ->assertSee('Воронкова Анна')
            ->assertSee('Выгрузить CSV')
            ->assertSee('🏁 Финал');
    }

    /** @test */
    public function page_hides_users_without_enrollment(): void
    {
        $outsider = User::factory()->create(['name' => 'Посторонний Тест']);
        $enrolled = User::factory()->create(['name' => 'Записанный Тест']);
        $this->enroll($enrolled);

        Livewire::actingAs($this->admin)
            ->test(MiniCourseProgress::class)
            ->assertSuccessful()
            ->assertSee('Записанный Тест')
            ->assertDontSee('Посторонний Тест');
    }

    /** @test */
    public function aggregate_counts_lessons_quizzes_homework_and_promo(): void
    {
        $student = User::factory()->create(['name' => 'Прогресс Тест', 'signup_source' => 'vk']);
        $this->enroll($student);

        $lessons = Lesson::where('course_id', $this->course->id)->orderBy('id')->get();

        // Урок 1 пройден, урок 2 начат (заметка без завершения).
        $student->lessonProgress()->attach($lessons[0]->id, ['is_completed' => true]);
        $student->lessonProgress()->attach($lessons[1]->id, ['is_completed' => false]);

        // Квиз этапа 1 сдан, этапа 2 — попытка без зачёта.
        $quiz1 = CourseQuiz::where('course_id', $this->course->id)->where('block_number', 1)->first();
        $q1 = CourseQuizQuestion::create([
            'course_quiz_id' => $quiz1->id, 'question' => '1+1?',
            'options' => ['2', '3'], 'correct_option' => 0, 'sort_order' => 0,
        ]);
        CourseQuizAttempt::create([
            'user_id' => $student->id, 'course_quiz_id' => $quiz1->id,
            'score' => 1, 'total' => 1, 'passed' => true, 'answers' => [$q1->id => 0],
        ]);
        $quiz2 = CourseQuiz::where('course_id', $this->course->id)->where('block_number', 2)->first();
        CourseQuizAttempt::create([
            'user_id' => $student->id, 'course_quiz_id' => $quiz2->id,
            'score' => 0, 'total' => 1, 'passed' => false, 'answers' => [],
        ]);

        // Домашка принята.
        HomeworkSubmission::create([
            'user_id' => $student->id, 'lesson_id' => $lessons[0]->id, 'course_id' => $this->course->id,
            'status' => 'accepted',
        ]);

        // Промокод выдан и погашен.
        $promo = PromoCode::create([
            'code' => 'PREP50-'.$student->id, 'type' => 'percent', 'value' => 50,
            'course_id' => $this->grammarCourse->id, 'usage_limit' => 1, 'is_active' => true,
        ]);
        Payment::create([
            'user_id' => $student->id, 'course_id' => $this->grammarCourse->id,
            'tariff' => 'full', 'amount' => 3000, 'status' => 'paid', 'promo_code_id' => $promo->id,
        ]);

        $rows = \App\Support\MiniCourseProgress::rows($this->course->id);
        $this->assertCount(1, $rows);

        $row = $rows[$student->id];
        $this->assertSame(1, $row->lessons_completed);
        $this->assertSame(3, $row->lessons_total);
        $this->assertTrue($row->quizzes[1]['passed']);
        $this->assertFalse($row->quizzes[2]['passed']);
        $this->assertSame(0, $row->final_passed ? 1 : 0);
        $this->assertSame(1, $row->hw_accepted);
        $this->assertSame('PREP50-'.$student->id, $row->promo_code);
        $this->assertTrue($row->promo_redeemed);
        $this->assertSame('vk', $row->signup_source);
    }

    /** @test */
    public function manager_can_access_but_plain_user_cannot(): void
    {
        $manager = User::factory()->create(['role' => Roles::MANAGER]);

        Livewire::actingAs($manager)->test(MiniCourseProgress::class)->assertSuccessful();
        $this->actingAs(User::factory()->create())
            ->get(MiniCourseProgress::getUrl())
            ->assertForbidden();
    }

    /** @test */
    public function export_action_is_registered_on_the_page(): void
    {
        $student = User::factory()->create(['name' => 'Экспортная Анна']);
        $this->enroll($student);

        Livewire::actingAs($this->admin)
            ->test(MiniCourseProgress::class)
            ->assertActionVisible('export');
    }
}
