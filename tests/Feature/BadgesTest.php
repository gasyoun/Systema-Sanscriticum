<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\PranaTransaction;
use App\Models\ReferralReward;
use App\Models\User;
use App\Support\Badges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Бейджи вычисляются из существующих сигналов (уроки/курсы/рефералы/прана).
 */
class BadgesTest extends TestCase
{
    use RefreshDatabase;

    private function completeLessons(User $user, int $count): void
    {
        $course = Course::factory()->create();
        for ($i = 0; $i < $count; $i++) {
            $lesson = Lesson::factory()->for($course)->create();
            $user->lessonProgress()->attach($lesson->id, ['is_completed' => true]);
        }
    }

    /** @test */
    public function fresh_student_has_no_badges_earned(): void
    {
        $user = User::factory()->create(['lifetime_prana' => 0]);

        $badges = Badges::for($user);

        $this->assertSame(0, Badges::earnedCount($user));
        $this->assertGreaterThanOrEqual(8, $badges->count());
        $this->assertTrue($badges->every(fn ($b) => $b['earned'] === false));
    }

    /** @test */
    public function lesson_and_prana_badges_unlock_by_signals(): void
    {
        $user = User::factory()->create(['lifetime_prana' => 1000]);
        $this->completeLessons($user, 10);

        $byKey = Badges::for($user)->keyBy('key');

        $this->assertTrue($byKey['first_lesson']['earned']);   // >= 1
        $this->assertTrue($byKey['diligent']['earned']);       // >= 10
        $this->assertFalse($byKey['scholar']['earned']);       // < 50
        $this->assertTrue($byKey['prana_keeper']['earned']);   // lifetime >= 1000
        // прогресс «эрудита»: 10 / 50 = 20%
        $this->assertSame(20, $byKey['scholar']['progress']);
    }

    /** @test */
    public function referral_and_gift_badges_use_their_signals(): void
    {
        $user = User::factory()->create();

        // Один успешный реферал → mentor, но не ambassador (нужно 3).
        $invitee = User::factory()->create();
        ReferralReward::create(['referrer_id' => $user->id, 'referred_id' => $invitee->id, 'amount' => 500]);

        // Подарок праны → generous.
        PranaTransaction::create(['user_id' => $user->id, 'amount' => -50, 'reason' => 'p2p_sent']);

        $byKey = Badges::for($user)->keyBy('key');

        $this->assertTrue($byKey['mentor']['earned']);
        $this->assertFalse($byKey['ambassador']['earned']);
        $this->assertTrue($byKey['generous']['earned']);
    }

    /** @test */
    public function earned_badges_sort_before_locked(): void
    {
        $user = User::factory()->create(['lifetime_prana' => 1000]); // prana_keeper earned

        $badges = Badges::for($user);

        // Первый бейдж — полученный.
        $this->assertTrue($badges->first()['earned']);
    }
}
