<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\SalaryClosedPeriod;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Закрытие периодов ЗП (per teacher) + ролл-форвард: начисления за закрытый
 * месяц переносятся в ближайший открытый.
 */
class SalaryPeriodCloseTest extends TestCase
{
    use RefreshDatabase;

    private function percentCourseWithBlock5(Teacher $teacher): Course
    {
        $course = Course::factory()->create([
            'teacher_id' => $teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 10,
        ]);
        // Блок 5 шёл в мае 2026.
        CourseBlock::create(['course_id' => $course->id, 'number' => 5, 'starts_at' => '2026-05-10', 'is_active' => true]);

        return $course;
    }

    private function payLateBlock5(Course $course, float $amount): void
    {
        $u = User::factory()->create();
        Payment::withoutEvents(function () use ($course, $u, $amount) {
            Payment::create([
                'user_id' => $u->id, 'course_id' => $course->id, 'amount' => $amount,
                'tariff' => 'block_5', 'start_block' => 5, 'end_block' => 5,
                'status' => 'paid', 'created_at' => '2026-07-20 10:00:00', // оплата позже, чем шёл блок
            ]);
        });
    }

    private function payBlock5At(Course $course, float $amount, string $createdAt): void
    {
        $u = User::factory()->create();
        Payment::withoutEvents(function () use ($course, $u, $amount, $createdAt) {
            Payment::create([
                'user_id' => $u->id, 'course_id' => $course->id, 'amount' => $amount,
                'tariff' => 'block_5', 'start_block' => 5, 'end_block' => 5,
                'status' => 'paid', 'created_at' => $createdAt,
            ]);
        });
    }

    private function close(Teacher $teacher, string $month): void
    {
        SalaryClosedPeriod::create([
            'teacher_id' => $teacher->id,
            'period_month' => $month,
            'closed_at' => now(),
        ]);
    }

    private function earnedInMonth(int $teacherId, string $month): float
    {
        $teacher = Teacher::with('courses')->find($teacherId);
        $start = Carbon::parse($month.'-01')->startOfMonth();

        // Свежий сервис — чтобы request-scoped кэши не держали старое состояние закрытых месяцев.
        return app(TeacherSalaryService::class)->totalForTeacher($teacher, $start, $start->copy()->endOfMonth());
    }

    /** @test */
    public function late_payment_rolls_forward_when_block_month_is_closed(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = $this->percentCourseWithBlock5($teacher);
        $this->payLateBlock5($course, 2000); // 10% = 200

        // Без закрытия — признаётся в месяце блока (май).
        $this->assertSame(200.0, $this->earnedInMonth($teacher->id, '2026-05'));

        // Закрываем май → переносится в ближайший открытый (июнь).
        $this->close($teacher, '2026-05');
        $this->assertSame(0.0, $this->earnedInMonth($teacher->id, '2026-05'));
        $this->assertSame(200.0, $this->earnedInMonth($teacher->id, '2026-06'));

        // Закрываем и июнь → уезжает в июль.
        $this->close($teacher, '2026-06');
        $this->assertSame(0.0, $this->earnedInMonth($teacher->id, '2026-06'));
        $this->assertSame(200.0, $this->earnedInMonth($teacher->id, '2026-07'));

        // Итог за всё время не меняется.
        $this->assertSame(200.0, app(TeacherSalaryService::class)->totalForTeacher(Teacher::with('courses')->find($teacher->id)));
    }

    /** @test */
    public function payment_created_before_close_stays_in_the_closed_month(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = $this->percentCourseWithBlock5($teacher);
        $this->payBlock5At($course, 2000, '2026-05-20 10:00:00'); // 10% = 200

        $this->close($teacher, '2026-05');

        $this->assertSame(200.0, $this->earnedInMonth($teacher->id, '2026-05'));
        $this->assertSame(0.0, $this->earnedInMonth($teacher->id, '2026-06'));
        $this->assertSame(200.0, app(TeacherSalaryService::class)->totalForTeacher(Teacher::with('courses')->find($teacher->id)));
    }

    /** @test */
    public function closing_one_teacher_does_not_affect_another(): void
    {
        $a = Teacher::create(['name' => 'A']);
        $b = Teacher::create(['name' => 'B']);
        $this->payLateBlock5($this->percentCourseWithBlock5($a), 1000); // 100
        $this->payLateBlock5($this->percentCourseWithBlock5($b), 1000); // 100

        $this->close($a, '2026-05');

        // У A май перенесён, у B — на месте.
        $this->assertSame(0.0, $this->earnedInMonth($a->id, '2026-05'));
        $this->assertSame(100.0, $this->earnedInMonth($a->id, '2026-06'));
        $this->assertSame(100.0, $this->earnedInMonth($b->id, '2026-05'));
    }

    /** @test */
    public function reopening_restores_recognition_into_the_month(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = $this->percentCourseWithBlock5($teacher);
        $this->payLateBlock5($course, 2000);

        $this->close($teacher, '2026-05');
        $this->assertSame(0.0, $this->earnedInMonth($teacher->id, '2026-05'));

        // Открываем месяц обратно.
        SalaryClosedPeriod::where('teacher_id', $teacher->id)->where('period_month', '2026-05')->delete();
        $this->assertSame(200.0, $this->earnedInMonth($teacher->id, '2026-05'));
    }

    /** @test */
    public function unrelated_closed_month_does_not_touch_open_block(): void
    {
        $teacher = Teacher::create(['name' => 'Препод']);
        $course = $this->percentCourseWithBlock5($teacher);
        $this->payLateBlock5($course, 2000);

        // Закрыт другой месяц — май не трогаем.
        $this->close($teacher, '2026-01');

        $this->assertSame(200.0, $this->earnedInMonth($teacher->id, '2026-05'));
    }
}
