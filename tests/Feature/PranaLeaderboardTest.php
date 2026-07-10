<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarathonEnrollment;
use App\Models\User;
use App\Support\PranaLeaderboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
