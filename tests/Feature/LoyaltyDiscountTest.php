<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tariff::getDiscountPercentForUser — единственная ветка ценообразования, у
 * которой не было прямого теста: DepositTest дублировал SQL-фильтр, т.к. в
 * SQLite-схеме тестов отсутствовали wholesale_*-поля (миграция
 * 2026_03_27_182336 теперь чинится — drop+add разнесены). Здесь проверяем
 * пороги лояльности напрямую через метод.
 */
class LoyaltyDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();
    }

    private function loyalty(bool $active): void
    {
        MarketingSetting::create([
            'is_loyalty_active' => $active,
            'wholesale_small_threshold' => 2,
            'wholesale_small_discount' => 10,
            'wholesale_large_threshold' => 4,
            'wholesale_large_discount' => 20,
        ]);
    }

    private function payCourses(User $user, int $n, string $tariff = 'full'): void
    {
        for ($i = 0; $i < $n; $i++) {
            $course = Course::factory()->create();
            Payment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'amount' => 4800,
                'tariff' => $tariff,
                'status' => 'paid',
            ]);
        }
    }

    private function percentFor(User $user): int
    {
        return (new Tariff)->getDiscountPercentForUser($user->fresh());
    }

    /** @test */
    public function no_discount_when_loyalty_disabled(): void
    {
        $this->loyalty(false);
        $user = User::factory()->create();
        $this->payCourses($user, 5);

        $this->assertSame(0, $this->percentFor($user));
    }

    /** @test */
    public function no_discount_below_small_threshold(): void
    {
        $this->loyalty(true);
        $user = User::factory()->create();
        $this->payCourses($user, 1); // порог small = 2

        $this->assertSame(0, $this->percentFor($user));
    }

    /** @test */
    public function small_tier_at_its_threshold(): void
    {
        $this->loyalty(true);
        $user = User::factory()->create();
        $this->payCourses($user, 2);

        $this->assertSame(10, $this->percentFor($user));
    }

    /** @test */
    public function large_tier_at_its_threshold(): void
    {
        $this->loyalty(true);
        $user = User::factory()->create();
        $this->payCourses($user, 4);

        $this->assertSame(20, $this->percentFor($user));
    }

    /** @test */
    public function deposit_and_trial_do_not_count_toward_loyalty(): void
    {
        $this->loyalty(true);
        $user = User::factory()->create();

        // 1 настоящий курс + депозит + пробное (3 платежа, но «курс» один) —
        // ниже small-порога (2), поэтому скидки нет. Если бы депозит/пробное
        // считались, счёт = 3 и скидка бы появилась.
        $this->payCourses($user, 1, 'full');
        $this->payCourses($user, 1, 'deposit');
        $this->payCourses($user, 1, 'trial');

        $this->assertSame(0, $this->percentFor($user));
    }
}
