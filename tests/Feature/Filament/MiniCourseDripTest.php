<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Mail\MiniCourseLessonOpenedMail;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\MiniCourseDripEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Дрип мини-курса: уроки открываются студенту по расписанию «раз в 3 дня»
 * от даты записи; закрытый урок — страница с датой открытия; команда
 * шлёт письма по самому свежему открытому уроку (идемпотентно).
 */
class MiniCourseDripTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $admin;

    private Course $course;

    /** @var array<int, Lesson> */
    private array $lessons = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['mini_courses.slug' => 'drip-test-course']);
        config(['mini_courses.drip_days' => 3]);
        config(['mini_courses.grammar_course_id' => 0]);
        config(['mini_courses.trial_course_id' => 0]);

        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_admin' => true]);
        $this->student = User::factory()->create(['email' => 'drip-student@example.com']);

        $group = Group::create(['name' => 'G-drip']);
        $this->student->groups()->attach($group);

        $this->course = Course::factory()->create(['is_active' => true, 'slug' => 'drip-test-course']);
        $this->course->groups()->attach($group);

        foreach (['Урок 1', 'Урок 2', 'Урок 3', 'Урок 4'] as $i => $title) {
            $this->lessons[$i + 1] = Lesson::factory()->create([
                'course_id' => $this->course->id,
                'title' => $title,
                'is_free' => true,
            ]);
        }

        // Запись «сейчас».
        DB::table('course_user')->insert([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function first_lesson_is_open_right_after_enrollment(): void
    {
        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lessons[1]->id]))
            ->assertOk()
            ->assertSee('Урок 1');
    }

    /** @test */
    public function next_lesson_shows_locked_view_with_open_date(): void
    {
        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lessons[2]->id]))
            ->assertOk()
            ->assertSee('Урок 2')
            ->assertSee('откроется')
            // Контент закрытого урока не рендерится: нет кнопки завершения.
            ->assertDontSee('Завершить и продолжить');
    }

    /** @test */
    public function lessons_unlock_after_three_day_steps(): void
    {
        // Студент записался 7 дней назад: открыты уроки 1 (день 0), 2 (день 3), 3 (день 6); урок 4 (день 9) закрыт.
        DB::table('course_user')
            ->where('user_id', $this->student->id)
            ->update(['created_at' => now()->subDays(7)]);

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lessons[3]->id]))
            ->assertOk()
            ->assertSee('Урок 3');

        $this->actingAs($this->student)
            ->get(route('student.lesson', [$this->course->slug, $this->lessons[4]->id]))
            ->assertOk()
            ->assertSee('откроется');
    }

    /** @test */
    public function admin_bypasses_drip(): void
    {
        $this->actingAs($this->admin)
            ->get(route('student.lesson', [$this->course->slug, $this->lessons[2]->id]))
            ->assertOk()
            ->assertSee('Урок 2')
            ->assertDontSee('откроется');
    }

    /** @test */
    public function course_page_shows_open_dates_for_locked_lessons(): void
    {
        $this->actingAs($this->student)
            ->get(route('student.course', $this->course->slug))
            ->assertOk()
            ->assertSee('Откроется', false);
    }

    /** @test */
    public function drip_command_sends_email_for_newest_open_lesson_and_marks_all(): void
    {
        Mail::fake();

        // Запись 10 дней назад: открыты уроки 1–4 (день 0/3/6/9).
        DB::table('course_user')
            ->where('user_id', $this->student->id)
            ->update(['created_at' => now()->subDays(10)]);

        $this->artisan('mini-course:send-drip-emails')->assertSuccessful();

        Mail::assertQueued(function (MiniCourseLessonOpenedMail $mail) {
            return $mail->lesson->id === $this->lessons[4]->id;
        }, 1);

        // Все открытые помечены отправленными.
        $this->assertSame(4, MiniCourseDripEmail::where('user_id', $this->student->id)->count());

        // Повторный прогон — ничего не шлёт.
        $this->artisan('mini-course:send-drip-emails')->assertSuccessful();
        Mail::assertQueued(MiniCourseLessonOpenedMail::class, 1);
    }

    /** @test */
    public function drip_command_skips_admins(): void
    {
        DB::table('course_user')->insert([
            'user_id' => $this->admin->id,
            'course_id' => $this->course->id,
            'created_at' => now()->subDays(30),
            'updated_at' => now(),
        ]);

        Mail::fake();

        $this->artisan('mini-course:send-drip-emails')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
