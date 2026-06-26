<?php

declare(strict_types=1);

namespace Tests\Feature;

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
}
