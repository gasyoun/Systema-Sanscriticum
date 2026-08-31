<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Гашение спящих тарифов скрытого курса (H3773, случай курса 327).
 *
 * Главная проверка — та, что доступ купивших остаётся нетронутым: он считается
 * ключами `payments.tariff`, а не строками `tariffs`.
 */
class DeactivateDormantTariffsTest extends TestCase
{
    use RefreshDatabase;

    private function hiddenCourseWithTariffs(int $tariffs = 3): Course
    {
        $course = Course::factory()->create([
            'title' => 'Йога-сутры Патанджали (1 поток, 2025) в записи',
            'slug' => 'ys-dormant-test',
            'is_visible' => false,
        ]);

        Tariff::factory()->for($course)->create();
        for ($i = 1; $i < $tariffs; $i++) {
            Tariff::factory()->for($course)->block($i)->create();
        }

        return $course;
    }

    /** @test */
    public function without_apply_nothing_changes(): void
    {
        $course = $this->hiddenCourseWithTariffs();

        $this->artisan('catalog:deactivate-dormant-tariffs '.$course->id)->assertExitCode(0);

        $this->assertSame(3, $course->tariffs()->where('is_active', true)->count());
    }

    /** @test */
    public function with_apply_the_dormant_tariffs_go_quiet(): void
    {
        $course = $this->hiddenCourseWithTariffs();

        $this->artisan('catalog:deactivate-dormant-tariffs '.$course->id.' --apply')->assertExitCode(0);

        $this->assertSame(0, $course->tariffs()->where('is_active', true)->count());
        $this->assertSame(3, $course->tariffs()->count(), 'тарифы гасятся, а не удаляются');
    }

    /** @test */
    public function a_visible_course_is_refused(): void
    {
        // Снятие товара с продажи — продуктовое решение, не гигиена.
        $course = $this->hiddenCourseWithTariffs();
        $course->update(['is_visible' => true]);

        $this->artisan('catalog:deactivate-dormant-tariffs '.$course->id.' --apply')->assertExitCode(1);

        $this->assertSame(3, $course->tariffs()->where('is_active', true)->count());
    }

    /** @test */
    public function a_paid_student_keeps_access_to_every_lesson_they_bought(): void
    {
        // Сердце проверки: доступ живёт в payments.tariff, а не в tariffs.
        $course = $this->hiddenCourseWithTariffs();
        CourseBlock::factory()->for($course)->create(['number' => 1]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'block_number' => 1,
            'is_preview' => false,
        ]);

        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->assertTrue($lesson->isUnlockedBy(['full']), 'до гашения урок открыт');

        $this->artisan('catalog:deactivate-dormant-tariffs '.$course->id.' --apply')->assertExitCode(0);

        $this->assertTrue(
            $lesson->fresh()->isUnlockedBy(['full']),
            'после гашения тарифов урок по-прежнему открыт купившему',
        );
        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
    }

    /** @test */
    public function enrolments_and_payments_are_untouched(): void
    {
        $course = $this->hiddenCourseWithTariffs();
        $user = User::factory()->create();
        $course->users()->attach($user->id);
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->artisan('catalog:deactivate-dormant-tariffs '.$course->id.' --apply')->assertExitCode(0);

        $this->assertSame(1, $course->users()->count());
        $this->assertSame(1, $course->payments()->paid()->count());
    }

    /** @test */
    public function a_course_with_no_active_tariffs_is_a_no_op(): void
    {
        $course = Course::factory()->create([
            'title' => 'Уже погашен',
            'slug' => 'already-quiet-test',
            'is_visible' => false,
        ]);
        Tariff::factory()->for($course)->create(['is_active' => false]);

        $this->artisan('catalog:deactivate-dormant-tariffs '.$course->id.' --apply')->assertExitCode(0);

        $this->assertSame(1, $course->tariffs()->count());
    }

    /** @test */
    public function another_courses_tariffs_are_not_touched(): void
    {
        $hidden = $this->hiddenCourseWithTariffs();
        $other = Course::factory()->create(['title' => 'Живой курс', 'slug' => 'live-neighbour-test']);
        Tariff::factory()->for($other)->create();

        $this->artisan('catalog:deactivate-dormant-tariffs '.$hidden->id.' --apply')->assertExitCode(0);

        $this->assertSame(1, $other->tariffs()->where('is_active', true)->count());
    }

    /** @test */
    public function it_resolves_a_course_by_slug_too(): void
    {
        $course = $this->hiddenCourseWithTariffs();

        $this->artisan('catalog:deactivate-dormant-tariffs '.$course->slug.' --apply')->assertExitCode(0);

        $this->assertSame(0, $course->tariffs()->where('is_active', true)->count());
    }
}
