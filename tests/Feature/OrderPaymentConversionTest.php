<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reports\OrderPaymentConversionService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ручная сверка конверсии «оформленный заказ → оплата» и списка недожатых
 * заказов (H262) на известной синтетической когорте. Заказ = реальный платёж,
 * кроме исключённых тарифов; конверсия когортна по дате оформления. Пороги/окна
 * фиксируем из config/conversion.php, чтобы тест не зависел от .env.
 */
class OrderPaymentConversionTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-08 12:00:00'));

        config()->set('conversion.target_pct', 63);
        config()->set('conversion.warn_pct', 50);
        config()->set('conversion.unclosed_after_days', 3);
        config()->set('conversion.headline_window_days', 30);
        config()->set('conversion.breakdown_window_days', 90);
        config()->set('conversion.excluded_tariffs', ['Расход', 'salary_payout', 'deposit', 'trial']);

        $this->course = Course::factory()->create(['title' => 'Санскрит']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Заказ без Eloquent-событий (минуем grantAccess/письма/прану — сервис читает
     * только колонки). created_at фиксируем явно (он в $fillable Payment).
     */
    private function order(array $attrs): Payment
    {
        return Payment::withoutEvents(fn (): Payment => Payment::create(array_merge([
            'course_id' => $this->course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'pending',
            'is_conditional' => false,
        ], $attrs)));
    }

    private function service(): OrderPaymentConversionService
    {
        return app(OrderPaymentConversionService::class);
    }

    /** @test */
    public function headline_conversion_counts_paid_over_orders_in_window(): void
    {
        $u = User::factory()->create();
        $at = '2026-06-25 10:00:00'; // внутри окна 30 дней

        // 6 оплаченных + 3 pending + 1 отменённый = 10 заказов, конверсия 60 %.
        for ($i = 0; $i < 6; $i++) {
            $this->order(['user_id' => $u->id, 'status' => 'paid', 'created_at' => $at]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->order(['user_id' => $u->id, 'status' => 'pending', 'created_at' => $at]);
        }
        $this->order(['user_id' => $u->id, 'status' => 'canceled', 'created_at' => $at]);

        $snap = $this->service()->snapshot();

        $this->assertSame(10, $snap['orders']);
        $this->assertSame(6, $snap['paid']);
        $this->assertSame(3, $snap['pending_open']);
        $this->assertSame(1, $snap['lost']);
        $this->assertSame(60.0, $snap['conversion_pct']);
        $this->assertSame('warning', $snap['level']); // 60 между warn 50 и target 63
    }

    /** @test */
    public function excluded_tariffs_and_conditional_do_not_count_as_orders(): void
    {
        $u = User::factory()->create();
        $at = '2026-06-25 10:00:00';

        $this->order(['user_id' => $u->id, 'status' => 'paid', 'created_at' => $at]); // 1 настоящий заказ
        // Не заказы курса: техрасход, бронь, пробное, выплата ЗП, conditional-доступ.
        $this->order(['user_id' => $u->id, 'status' => 'paid', 'tariff' => 'Расход', 'created_at' => $at]);
        $this->order(['user_id' => $u->id, 'status' => 'paid', 'tariff' => 'deposit', 'created_at' => $at]);
        $this->order(['user_id' => $u->id, 'status' => 'paid', 'tariff' => 'trial', 'created_at' => $at]);
        $this->order(['user_id' => $u->id, 'status' => 'paid', 'tariff' => 'salary_payout', 'created_at' => $at]);
        $this->order(['user_id' => $u->id, 'status' => 'paid', 'is_conditional' => true, 'created_at' => $at]);

        $snap = $this->service()->snapshot();

        $this->assertSame(1, $snap['orders']);
        $this->assertSame(1, $snap['paid']);
        $this->assertSame(100.0, $snap['conversion_pct']);
        $this->assertSame('success', $snap['level']);
    }

    /** @test */
    public function green_at_or_above_target_red_below_warn(): void
    {
        $u = User::factory()->create();
        $at = '2026-06-25 10:00:00';

        // 4 из 10 = 40 % → красный (< warn 50).
        for ($i = 0; $i < 4; $i++) {
            $this->order(['user_id' => $u->id, 'status' => 'paid', 'created_at' => $at]);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->order(['user_id' => $u->id, 'status' => 'canceled', 'created_at' => $at]);
        }

        $snap = $this->service()->snapshot();

        $this->assertSame(40.0, $snap['conversion_pct']);
        $this->assertSame('danger', $snap['level']);
    }

    /** @test */
    public function unclosed_list_holds_only_pending_orders_older_than_threshold(): void
    {
        $u = User::factory()->create(['name' => 'Ученик Тест']);

        // Недожатые: pending старше 3 дней.
        $this->order(['user_id' => $u->id, 'status' => 'pending', 'amount' => 15000, 'created_at' => '2026-06-20 10:00:00']); // 18 дн
        $this->order(['user_id' => $u->id, 'status' => 'pending', 'amount' => 5000, 'created_at' => '2026-07-01 10:00:00']);  // 7 дн
        // НЕ недожатые: свежий pending (< 3 дн), оплаченный, отменённый.
        $this->order(['user_id' => $u->id, 'status' => 'pending', 'amount' => 9999, 'created_at' => '2026-07-07 10:00:00']); // 1 дн
        $this->order(['user_id' => $u->id, 'status' => 'paid', 'amount' => 8888, 'created_at' => '2026-06-20 10:00:00']);
        $this->order(['user_id' => $u->id, 'status' => 'canceled', 'amount' => 7777, 'created_at' => '2026-06-20 10:00:00']);

        $snap = $this->service()->snapshot();
        $unclosed = $snap['unclosed'];

        $this->assertSame(2, $unclosed['count']);
        $this->assertSame(20000.0, $unclosed['sum']); // 15000 + 5000
        // Старейший сверху (кого дожать в первую очередь).
        $this->assertSame('2026-06-20', $unclosed['rows'][0]['created_at']);
        $this->assertSame(18, $unclosed['rows'][0]['age_days']);
        $this->assertSame('Ученик Тест', $unclosed['rows'][0]['user']);
        $this->assertSame(2, $this->service()->unclosedCount());
    }

    /** @test */
    public function breakdown_by_channel_uses_buyer_utm_source(): void
    {
        $vk = User::factory()->create(['utm_source' => 'vk']);
        $direct = User::factory()->create(['utm_source' => null]);
        $at = '2026-06-25 10:00:00';

        // vk: 1 оплачен из 2 = 50 %. direct: 2 из 2 = 100 %.
        $this->order(['user_id' => $vk->id, 'status' => 'paid', 'created_at' => $at]);
        $this->order(['user_id' => $vk->id, 'status' => 'pending', 'created_at' => $at]);
        $this->order(['user_id' => $direct->id, 'status' => 'paid', 'created_at' => $at]);
        $this->order(['user_id' => $direct->id, 'status' => 'success', 'created_at' => $at]);

        $snap = $this->service()->snapshot();
        $byChannel = collect($snap['by_channel']);

        $this->assertSame(50.0, $byChannel->firstWhere('channel', 'vk')['conversion_pct']);
        $this->assertSame(100.0, $byChannel->firstWhere('channel', 'direct')['conversion_pct']);
    }

    /** @test */
    public function breakdown_by_course_splits_conversion_per_course(): void
    {
        $other = Course::factory()->create(['title' => 'Пали']);
        $u = User::factory()->create();
        $at = '2026-06-25 10:00:00';

        $this->order(['user_id' => $u->id, 'course_id' => $this->course->id, 'status' => 'paid', 'created_at' => $at]);
        $this->order(['user_id' => $u->id, 'course_id' => $this->course->id, 'status' => 'canceled', 'created_at' => $at]);
        $this->order(['user_id' => $u->id, 'course_id' => $other->id, 'status' => 'paid', 'created_at' => $at]);

        $snap = $this->service()->snapshot();
        $byCourse = collect($snap['by_course']);

        $this->assertSame(50.0, $byCourse->firstWhere('course', 'Санскрит')['conversion_pct']);
        $this->assertSame(100.0, $byCourse->firstWhere('course', 'Пали')['conversion_pct']);
    }

    /** @test */
    public function conversion_is_null_when_no_orders(): void
    {
        $snap = $this->service()->snapshot();

        $this->assertNull($snap['conversion_pct']);
        $this->assertSame('gray', $snap['level']);
        $this->assertSame(0, $snap['orders']);
    }

    /** @test */
    public function page_renders_for_finance_role(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);

        $this->actingAs($admin)->get('/admin/order-payment-conversion')->assertSuccessful();
    }

    /** @test */
    public function manager_cannot_access_page(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'is_admin' => true]);

        $this->actingAs($manager)->get('/admin/order-payment-conversion')->assertForbidden();
    }
}
