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

    /** @test */
    public function unreliable_student_gets_no_loyalty_discount(): void
    {
        // «Прана сгорает»: неблагонадёжному студенту loyalty-скидка отключается,
        // даже если он перешагнул порог. Снять может только менеджер вручную.
        $this->loyalty(true);
        $user = User::factory()->create(['is_unreliable' => true]);
        $this->payCourses($user, 4); // заведомо выше large-порога (4)

        $this->assertTrue($user->fresh()->isUnreliable());
        $this->assertSame(0, $this->percentFor($user));

        // После снятия флага скидка восстанавливается на том же наборе оплат.
        $user->clearUnreliable();
        $this->assertSame(20, $this->percentFor($user));
    }

    /** @test */
    public function repeat_payments_on_the_same_course_count_once(): void
    {
        $this->loyalty(true);
        $user = User::factory()->create();

        // Три платежа, но уникальный курс один; платёж без course_id не считается.
        $course = Course::factory()->create();
        foreach (['block_1', 'block_2'] as $tariff) {
            Payment::create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'amount' => 2400, 'tariff' => $tariff, 'status' => 'paid',
            ]);
        }
        Payment::create([
            'user_id' => $user->id, 'course_id' => null,
            'amount' => 4800, 'tariff' => 'full', 'status' => 'paid',
        ]);

        // small-порог = 2 уникальных курса — один курс не дотягивает.
        $this->assertSame(0, $this->percentFor($user));

        // Второй уникальный курс добирает порог.
        $this->payCourses($user, 1);
        $this->assertSame(10, $this->percentFor($user));
    }

    /** @test */
    public function discount_is_ignored_for_payments_older_than_a_year(): void
    {
        // Порог окна лояльности — последний год: оплаты старше года не считаются.
        $this->loyalty(true);
        $user = User::factory()->create();
        $this->payCourses($user, 4);

        // Состарим все платежи на 13 месяцев — счёт уникальных курсов в окне = 0.
        Payment::where('user_id', $user->id)->update(['created_at' => now()->subMonths(13)]);

        $this->assertSame(0, $this->percentFor($user));
    }
}
