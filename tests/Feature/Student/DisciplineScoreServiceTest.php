<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\Discipline\DisciplineScore;
use App\Services\DisciplineScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DisciplineScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Paying a course auto-enrolls the user (Payment::fireOnPaid →
     * processSuccessfulPayment), so a real payment created before this call
     * may already have inserted the course_user row — sync rather than
     * attach to avoid a duplicate-pivot error.
     */
    private function enroll(User $user, Course $course, ?int $joinedAtBlock = null): void
    {
        $exists = DB::table('course_user')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->exists();

        if ($exists) {
            if ($joinedAtBlock !== null) {
                DB::table('course_user')
                    ->where('user_id', $user->id)
                    ->where('course_id', $course->id)
                    ->update(['joined_at_block' => $joinedAtBlock]);
            }

            return;
        }

        $user->courses()->attach($course->id, array_filter([
            'status' => 'Записался',
            'joined_at_block' => $joinedAtBlock,
        ], fn ($v) => $v !== null));
    }

    private function realPayment(User $user, Course $course, int $block, \DateTimeInterface $createdAt): Payment
    {
        $payment = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 1000, 'tariff' => 'block_'.$block, 'status' => 'paid',
            'start_block' => $block, 'end_block' => $block, 'is_conditional' => false,
        ]);
        $payment->forceFill(['created_at' => $createdAt])->save();

        return $payment;
    }

    /** @test */
    public function on_time_payments_and_no_history_score_perfectly(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        $block = CourseBlock::factory()->for($course)->create([
            'number' => 1,
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->addWeek(),
        ]);
        $this->enroll($user, $course);
        $this->realPayment($user, $course, 1, now()->subWeek()->subDay());

        $score = app(DisciplineScoreService::class)->forUser($user);

        $this->assertSame(100, $score->score);
        $this->assertSame(DisciplineScore::TIER_GOOD, $score->tier);
        $this->assertSame(1.0, $score->signals['on_time_rate']);
        $this->assertSame(0, $score->signals['silence_events']);
    }

    /** @test */
    public function late_real_payment_does_not_count_as_on_time(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->create([
            'number' => 1,
            'starts_at' => now()->subWeeks(2),
            'ends_at' => now()->subWeek(),
        ]);
        $this->enroll($user, $course);
        // Оплачен, но ПОСЛЕ начала блока — не считается «вовремя».
        $this->realPayment($user, $course, 1, now()->subWeek()->addDay());

        $score = app(DisciplineScoreService::class)->forUser($user);

        $this->assertSame(0.0, $score->signals['on_time_rate']);
        $this->assertLessThan(100, $score->score);
    }

    /** @test */
    public function conditional_access_counts_as_credit_reliance(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->create([
            'number' => 1,
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->addWeek(),
        ]);
        $this->enroll($user, $course);
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 0, 'tariff' => 'block_1', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 1, 'is_conditional' => true,
        ]);

        $score = app(DisciplineScoreService::class)->forUser($user);

        $this->assertSame(1.0, $score->signals['credit_reliance_rate']);
        $this->assertSame(0.0, $score->signals['on_time_rate'], 'Conditional-платёж — не реальная оплата.');
    }

    /** @test */
    public function silence_event_is_counted_when_block_lapses_with_no_promise(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->create([
            'number' => 1,
            'starts_at' => now()->subWeeks(2),
            'ends_at' => now()->subWeek(),
        ]);
        $this->enroll($user, $course);
        // Блок истёк, не оплачен, обещаний по курсу не создавалось.

        $score = app(DisciplineScoreService::class)->forUser($user);

        $this->assertSame(1, $score->signals['silence_events']);
        // 1 молчание + on_time_rate=0 (единственный блок не оплачен) при
        // стартовых весах даёт 45 → Watch, не Poor: молчание штрафуется
        // тяжелее остальных сигналов, но не мгновенно проваливает тир.
        $this->assertSame(DisciplineScore::TIER_WATCH, $score->tier);
    }

    /** @test */
    public function a_promise_before_block_expiry_prevents_silence_event(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->create([
            'number' => 1,
            'starts_at' => now()->subWeeks(2),
            'ends_at' => now()->subWeek(),
        ]);
        $this->enroll($user, $course);
        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subWeeks(2)->toDateString(), 'amount' => 1000,
            'status' => PaymentPromise::STATUS_EXPIRED,
        ]);
        $promise->forceFill(['created_at' => now()->subWeeks(2)])->save();

        $score = app(DisciplineScoreService::class)->forUser($user);

        $this->assertSame(0, $score->signals['silence_events'], 'Студент вышел на связь — это не молчание, а сорванное обещание.');
    }

    /** @test */
    public function promise_keep_rate_only_counts_promises_fulfilled_on_or_before_the_promised_date(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);

        $kept = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subDays(5)->toDateString(), 'amount' => 1000,
            'status' => PaymentPromise::STATUS_FULFILLED,
        ]);
        $kept->forceFill(['fulfilled_at' => now()->subDays(5)])->save();

        $broken = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subDays(10)->toDateString(), 'amount' => 1000,
            'status' => PaymentPromise::STATUS_FULFILLED,
        ]);
        // Фактически закрыто на 3 дня позже обещанной даты — считается сорванным.
        $broken->forceFill(['fulfilled_at' => now()->subDays(7)])->save();

        $score = app(DisciplineScoreService::class)->forUser($user);

        $this->assertSame(0.5, $score->signals['promise_keep_rate']);
    }

    /** @test */
    public function tier_boundaries_follow_configured_thresholds(): void
    {
        config(['discipline.good_threshold' => 75, 'discipline.watch_threshold' => 40]);

        $this->assertSame(DisciplineScore::TIER_GOOD, $this->scoreWithSilenceEvents(0)->tier);
        $this->assertSame(DisciplineScore::TIER_WATCH, $this->scoreWithSilenceEvents(1)->tier);
        $this->assertSame(DisciplineScore::TIER_POOR, $this->scoreWithSilenceEvents(2)->tier);
    }

    private function scoreWithSilenceEvents(int $silenceEvents): DisciplineScore
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        for ($i = 1; $i <= $silenceEvents; $i++) {
            CourseBlock::factory()->for($course)->create([
                'number' => $i,
                'starts_at' => now()->subWeeks(2 + $i),
                'ends_at' => now()->subWeeks(1 + $i),
            ]);
        }
        // Как минимум один непроблемный блок, чтобы totalBlocks > 0 при silenceEvents=0.
        if ($silenceEvents === 0) {
            CourseBlock::factory()->for($course)->create([
                'number' => 1,
                'starts_at' => now()->subWeek(),
                'ends_at' => now()->addWeek(),
            ]);
            $this->realPayment($user, $course, 1, now()->subWeek()->subDay());
        }
        $this->enroll($user, $course);

        return app(DisciplineScoreService::class)->forUser($user);
    }

    /** @test */
    public function group_score_is_the_mean_of_active_members(): void
    {
        $group = Group::create(['name' => 'Test Group']);
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->create([
            'number' => 1,
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->addWeek(),
        ]);

        $goodUser = User::factory()->create();
        $this->enroll($goodUser, $course);
        $this->realPayment($goodUser, $course, 1, now()->subWeek()->subDay());

        $poorUser = User::factory()->create();
        $this->enroll($poorUser, $course);
        // poorUser не платит вовремя — блок остаётся будущим (ends_at в будущем),
        // поэтому не силенс, но on_time_rate=0 у него ниже, чем у goodUser.

        $leftUser = User::factory()->create();
        $group->users()->attach($leftUser->id, ['left_at' => now()->subDay()]);

        $group->users()->attach($goodUser->id);
        $group->users()->attach($poorUser->id);

        $result = app(DisciplineScoreService::class)->forGroup($group);

        $this->assertSame(2, $result['member_count'], 'Вышедший участник не считается.');
        $this->assertNotNull($result['score']);

        $service = app(DisciplineScoreService::class);
        $expected = (int) round(($service->forUser($goodUser)->score + $service->forUser($poorUser)->score) / 2);
        $this->assertSame($expected, $result['score']);
    }

    /** @test */
    public function group_with_no_active_members_returns_null_score(): void
    {
        $group = Group::create(['name' => 'Empty Group']);

        $result = app(DisciplineScoreService::class)->forGroup($group);

        $this->assertNull($result['score']);
        $this->assertNull($result['tier']);
        $this->assertSame(0, $result['member_count']);
    }
}
