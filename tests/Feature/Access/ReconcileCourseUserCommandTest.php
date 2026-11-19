<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * access:reconcile-course-user — сверка «оплачен, но доступа нет».
 * Сухой прогон только репортит; --apply пишет каноническими методами Payment
 * (grantAccess + enrollInCourse) и идемпотентен. Тарифы без доступа
 * (deposit/trial/…) и архив за окном --days не трогаются.
 */
class ReconcileCourseUserCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Платёж в paid-статусе БЕЗ course_user и групп: модельные события
     * обходятся прямым UPDATE, как это и выглядит в проде после импорта или
     * withoutEvents-создания (инцидент 14577/14066).
     */
    private function driftedPayment(array $attributes = []): Payment
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Course group '.uniqid('', false)]);
        $course->groups()->attach($group->id);

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => 'pending',
            ...$attributes,
        ]);

        DB::table('payments')->where('id', $payment->id)->update([
            'status' => 'paid',
            'first_paid_at' => $attributes['first_paid_at'] ?? now(),
        ]);

        return $payment->fresh();
    }

    /** @test */
    public function dry_run_reports_drift_but_writes_nothing(): void
    {
        $payment = $this->driftedPayment();

        $this->artisan('access:reconcile-course-user')->assertSuccessful();

        $this->assertDatabaseMissing('course_user', [
            'user_id' => $payment->user_id,
            'course_id' => $payment->course_id,
        ]);
        $this->assertTrue($payment->user->fresh()->groups->isEmpty());
    }

    /** @test */
    public function apply_grants_group_and_enrolls_into_course(): void
    {
        $payment = $this->driftedPayment();

        $this->artisan('access:reconcile-course-user', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('course_user', [
            'user_id' => $payment->user_id,
            'course_id' => $payment->course_id,
            'status' => 'Записался',
        ]);
        $courseGroup = DB::table('course_group')->where('course_id', $payment->course_id)->value('group_id');
        $this->assertTrue(
            $payment->user->fresh()->groups->pluck('id')->contains($courseGroup),
            'Группа курса должна быть выдана.'
        );
    }

    /** @test */
    public function apply_is_idempotent(): void
    {
        $payment = $this->driftedPayment();

        $this->artisan('access:reconcile-course-user', ['--apply' => true])->assertSuccessful();
        $this->artisan('access:reconcile-course-user', ['--apply' => true])
            ->expectsOutputToContain('Дрифт не найден')
            ->assertSuccessful();

        $this->assertDatabaseCount('course_user', 1);
    }

    /** @test */
    public function skips_non_access_tariffs_and_archive_but_fixes_old_promise_grants(): void
    {
        $deposit = $this->driftedPayment(['tariff' => 'deposit']);
        $trial = $this->driftedPayment(['tariff' => 'trial']);
        $archived = $this->driftedPayment(['first_paid_at' => now()->subDays(100)]);
        $oldPromiseGrant = $this->driftedPayment([
            'first_paid_at' => now()->subDays(100),
            'is_conditional' => true,
        ]);

        $this->artisan('access:reconcile-course-user', ['--apply' => true])->assertSuccessful();

        foreach ([$deposit, $trial, $archived] as $untouched) {
            $this->assertDatabaseMissing('course_user', [
                'user_id' => $untouched->user_id,
                'course_id' => $untouched->course_id,
            ]);
        }

        // Promise-grant — класс инцидента: чинится независимо от возраста.
        $this->assertDatabaseHas('course_user', [
            'user_id' => $oldPromiseGrant->user_id,
            'course_id' => $oldPromiseGrant->course_id,
        ]);
    }

    /** @test */
    public function payment_without_course_is_ignored(): void
    {
        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => null,
            'amount' => 1000,
            'tariff' => 'full',
            'status' => 'pending',
        ]);
        DB::table('payments')->where('id', $payment->id)->update(['status' => 'paid']);

        $this->artisan('access:reconcile-course-user', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseMissing('course_user', ['user_id' => $user->id]);
    }
}
