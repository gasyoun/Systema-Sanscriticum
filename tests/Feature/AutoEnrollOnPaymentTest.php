<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutoEnrollOnPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();
    }

    private function makeCourseWithGroup(): Course
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'G-'.$course->id]);
        $course->groups()->attach($group->id);

        return $course;
    }

    /** @test */
    public function real_payment_enrolls_student_with_zapisalsya_status(): void
    {
        $user = User::factory()->create();
        $course = $this->makeCourseWithGroup();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_2',
            'status' => 'paid',
            'start_block' => 2,
            'end_block' => 2,
        ]);

        $pivot = $user->fresh()->courses()->where('courses.id', $course->id)->first();
        $this->assertNotNull($pivot, 'Оплата блока должна записать студента на курс.');
        $this->assertSame('Записался', $pivot->pivot->status);
    }

    /** @test */
    public function conditional_payment_also_enrolls(): void
    {
        $user = User::factory()->create();
        $course = $this->makeCourseWithGroup();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 0,
            'tariff' => 'block_2',
            'status' => 'paid',
            'is_conditional' => true,
        ]);

        $this->assertTrue(
            $user->fresh()->courses()->where('courses.id', $course->id)->exists(),
            'Conditional-доступ под обещание тоже должен записывать на курс.'
        );
    }

    /** @test */
    public function existing_manual_status_is_not_overwritten(): void
    {
        $user = User::factory()->create();
        $course = $this->makeCourseWithGroup();

        // Студент ранее вышел с курса (ручной терминальный статус).
        $user->courses()->attach($course->id, ['status' => 'Покинул']);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $pivot = $user->fresh()->courses()->where('courses.id', $course->id)->first();
        $this->assertSame('Покинул', $pivot->pivot->status,
            'Авто-запись не должна затирать выставленный вручную статус.');
        $this->assertSame(1, DB::table('course_user')
            ->where('user_id', $user->id)->where('course_id', $course->id)->count());
    }

    /** @test */
    public function deposit_does_not_enroll(): void
    {
        $user = User::factory()->create();
        $course = $this->makeCourseWithGroup();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $this->assertFalse(
            $user->fresh()->courses()->where('courses.id', $course->id)->exists(),
            'Депозит (бронь) не должен записывать на курс.'
        );
    }

    /** @test */
    public function repeated_processing_does_not_duplicate_enrollment(): void
    {
        $user = User::factory()->create();
        $course = $this->makeCourseWithGroup();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        // Повторный прогон (имитация повторного webhook).
        $payment->processSuccessfulPayment();

        $this->assertSame(1, DB::table('course_user')
            ->where('user_id', $user->id)->where('course_id', $course->id)->count(),
            'Повторная обработка не должна плодить дубли course_user.');
    }

    /** @test */
    public function backfill_command_creates_missing_enrollments(): void
    {
        $user = User::factory()->create();
        $course = $this->makeCourseWithGroup();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        // Симулируем «легаси»-оплату без записи на курс: удаляем авто-созданную строку.
        DB::table('course_user')->where('user_id', $user->id)->where('course_id', $course->id)->delete();

        // dry-run ничего не пишет.
        Artisan::call('courses:backfill-enrollments', ['--dry-run' => true]);
        $this->assertSame(0, DB::table('course_user')->count());

        // Реальный прогон создаёт запись.
        Artisan::call('courses:backfill-enrollments');
        $pivot = $user->fresh()->courses()->where('courses.id', $course->id)->first();
        $this->assertNotNull($pivot);
        $this->assertSame('Записался', $pivot->pivot->status);

        // Повторный прогон не создаёт дублей.
        Artisan::call('courses:backfill-enrollments');
        $this->assertSame(1, DB::table('course_user')->count());
    }
}
