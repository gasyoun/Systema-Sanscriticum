<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\PranaTransaction;
use App\Models\User;
use App\Support\PranaLeaderboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Таблица лидеров по накопленной пране (lifetime_prana): порядок, исключение
 * админов/нулей, позиция, маскировка имён, подсветка текущего пользователя.
 */
class PranaLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    private function student(string $name, int $lifetime): User
    {
        return User::factory()->create(['name' => $name, 'is_admin' => false, 'lifetime_prana' => $lifetime]);
    }

    /** @test */
    public function rows_are_ordered_by_lifetime_desc_and_mask_names(): void
    {
        $this->student('Иванов Аруна, Москва', 300);
        $this->student('Петров Борис', 900);
        $this->student('Сидоров Вера', 600);

        $rows = PranaLeaderboard::rows(10);

        $this->assertSame([900, 600, 300], $rows->pluck('lifetime')->all());
        $this->assertSame([1, 2, 3], $rows->pluck('position')->all());
        // Маска: «Фамилия Имя, Город» → «Фамилия И.» (без города).
        $this->assertSame('Петров Б.', $rows->first()['display']);
        $this->assertStringNotContainsString('Москва', $rows->last()['display']);
    }

    /** @test */
    public function admins_and_zero_lifetime_are_excluded(): void
    {
        $this->student('Студент', 100);
        User::factory()->create(['name' => 'Админ', 'is_admin' => true, 'lifetime_prana' => 9999]);
        $this->student('Новичок', 0);

        $rows = PranaLeaderboard::rows(10);

        $this->assertCount(1, $rows);
        $this->assertSame('Студент', $rows->first()['display']);
    }

    /** @test */
    public function current_user_is_flagged_and_appended_when_outside_top(): void
    {
        // 12 студентов впереди + я на 13-м.
        for ($i = 0; $i < 12; $i++) {
            $this->student("Топ {$i}", 1000 + $i);
        }
        $me = $this->student('Я Студент', 50);

        $rows = PranaLeaderboard::rows(10, $me->id);

        // 10 из топа + моя строка отдельно (вне топ-10).
        $this->assertCount(11, $rows);
        $mine = $rows->firstWhere('is_me', true);
        $this->assertNotNull($mine);
        $this->assertSame(13, $mine['position']);
        $this->assertSame(13, PranaLeaderboard::positionFor($me->id));
    }

    /** @test */
    public function current_user_in_top_is_flagged_without_duplicate(): void
    {
        $me = $this->student('Лидер Я', 5000);
        $this->student('Второй', 100);

        $rows = PranaLeaderboard::rows(10, $me->id);

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->first()['is_me']);
        $this->assertSame(1, $rows->where('is_me', true)->count()); // без дубля
    }

    // --- H447 §5 leaderboard A/B: arm B (un-masked) is gated OFF by default ---

    private function studentWithArm(string $name, int $lifetime, string $arm): User
    {
        $lead = Lead::factory()->create();
        MarathonEnrollment::factory()->create(['lead_id' => $lead->id, 'ab_arm' => $arm]);

        return User::factory()->create([
            'name' => $name,
            'is_admin' => false,
            'lifetime_prana' => $lifetime,
            'lead_id' => $lead->id,
        ]);
    }

    /** @test */
    public function arm_b_stays_masked_when_the_unmask_gate_is_off(): void
    {
        config(['marathon.leaderboard_unmask_enabled' => false]);
        $this->studentWithArm('Иванов Аруна', 500, MarathonEnrollment::ARM_B);

        $rows = PranaLeaderboard::rows(10);

        $this->assertSame('Иванов А.', $rows->first()['display']);
    }

    /** @test */
    public function arm_a_stays_masked_even_when_the_unmask_gate_is_on(): void
    {
        config(['marathon.leaderboard_unmask_enabled' => true]);
        $this->studentWithArm('Петров Борис', 400, MarathonEnrollment::ARM_A);

        $rows = PranaLeaderboard::rows(10);

        $this->assertSame('Петров Б.', $rows->first()['display']);
    }

    /** @test */
    public function only_arm_b_unmasks_and_only_when_the_gate_is_on(): void
    {
        config(['marathon.leaderboard_unmask_enabled' => true]);
        $this->studentWithArm('Петров Борис', 400, MarathonEnrollment::ARM_A);
        $this->studentWithArm('Сидоров Виктор', 300, MarathonEnrollment::ARM_B);

        $rows = PranaLeaderboard::rows(10)->keyBy('lifetime');

        $this->assertSame('Петров Б.', $rows[400]['display']);
        $this->assertSame('Сидоров Виктор', $rows[300]['display']);
    }

    /** @test */
    public function unlinked_students_default_to_masked(): void
    {
        // No Lead/enrollment at all — armFor() returns null, never treated as arm B.
        config(['marathon.leaderboard_unmask_enabled' => true]);
        $this->student('Кузнецов Игорь', 200);

        $rows = PranaLeaderboard::rows(10);

        $this->assertSame('Кузнецов И.', $rows->first()['display']);
    }

    // --- H2051: Week / Month / All Time periods (Memrise-analogue) ---

    /** @test */
    public function week_period_sums_only_awards_in_current_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00')); // Wednesday

        $alice = $this->student('Алиса Топ', 0);
        $bob = $this->student('Боб Второй', 0);
        $old = $this->student('Старый Герой', 0);

        $this->awardAt($alice->id, 100, '2026-07-28 10:00:00'); // this week (Mon 27)
        $this->awardAt($bob->id, 40, '2026-07-29 09:00:00');
        // Spend must not count.
        $this->awardAt($bob->id, -10, '2026-07-29 10:00:00', 'shop');
        // Previous week — out of scope for week board, even if lifetime is huge.
        $this->awardAt($old->id, 999, '2026-07-20 10:00:00');
        $old->forceFill(['lifetime_prana' => 999])->save();

        $rows = PranaLeaderboard::rows(10, null, PranaLeaderboard::PERIOD_WEEK);

        $this->assertSame([100, 40], $rows->pluck('score')->all());
        $this->assertSame('Алиса Т.', $rows->first()['display']);
        $this->assertSame(PranaLeaderboard::PERIOD_WEEK, $rows->first()['period']);
        $this->assertFalse($rows->contains(fn ($r) => str_contains($r['display'], 'Старый')));

        Carbon::setTestNow();
    }

    /** @test */
    public function month_period_uses_calendar_month_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));

        $in = $this->student('Июльский', 0);
        $out = $this->student('Июньский', 0);

        $this->awardAt($in->id, 50, '2026-07-02 08:00:00', 'srs_review');
        $this->awardAt($out->id, 200, '2026-06-30 23:00:00', 'srs_review');

        $rows = PranaLeaderboard::rows(10, null, PranaLeaderboard::PERIOD_MONTH);

        $this->assertCount(1, $rows);
        $this->assertSame(50, $rows->first()['score']);
        $this->assertSame('Июльский', $rows->first()['display']);

        Carbon::setTestNow();
    }

    /** @test */
    public function rows_by_period_returns_all_three_keys(): void
    {
        $this->student('Все Время', 500);

        $boards = PranaLeaderboard::rowsByPeriod(10);

        $this->assertArrayHasKey('week', $boards);
        $this->assertArrayHasKey('month', $boards);
        $this->assertArrayHasKey('all', $boards);
        $this->assertSame(500, $boards['all']->first()['score']);
        $this->assertTrue($boards['week']->isEmpty());
    }

    /** @test */
    public function position_for_period_counts_ahead_scores(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00'));

        $a = $this->student('А', 0);
        $b = $this->student('Б', 0);
        $this->awardAt($a->id, 80, '2026-07-29 11:00:00');
        $this->awardAt($b->id, 20, '2026-07-29 11:00:00');

        $this->assertSame(1, PranaLeaderboard::positionFor($a->id, 'week'));
        $this->assertSame(2, PranaLeaderboard::positionFor($b->id, 'week'));

        Carbon::setTestNow();
    }

    private function awardAt(int $userId, int $amount, string $at, string $reason = 'lesson_complete'): void
    {
        PranaTransaction::forceCreate([
            'user_id' => $userId,
            'amount' => $amount,
            'reason' => $reason,
            'created_at' => Carbon::parse($at),
        ]);
    }
}
