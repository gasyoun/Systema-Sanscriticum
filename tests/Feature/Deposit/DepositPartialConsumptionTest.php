<?php

declare(strict_types=1);

namespace Tests\Feature\Deposit;

use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Money-core H071 #9 + #10 — общий корень: учёт депозита «всё или ничего».
 *
 * #10: consumeDepositsForCourse гасил ВЕСЬ депозит, даже когда покупка дешевле —
 *      излишек молча сгорал. Теперь депозит гасится ровно на зачтённую в заказ
 *      сумму (payments.deposit_credit_applied), остаток (amount − consumed_amount)
 *      переживает покупку и зачитывается в следующую.
 *
 * #9:  upgradeCreditForUser суммировал только net-кэш (amount) содержащихся
 *      тарифов — депозитная часть половины терялась при докупке целого блока,
 *      и студент переплачивал ровно на сумму депозита. Теперь зачёт при докупке
 *      = amount + deposit_credit_applied.
 */
class DepositPartialConsumptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/abc',
                    'paymentLinkId' => 'tochka_tx_001',
                ],
            ], 200),
        ]);
    }

    private function deposit(User $user, Course $course, float $amount): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);
    }

    /** @test */
    public function residual_deposit_survives_purchase_cheaper_than_deposit(): void
    {
        // H071 #10: депозит 5000, покупка за 4000 → цена 0, доступ сразу;
        // из депозита списано 4000, остаток 1000 живёт и зачитывается дальше.
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $deposit = $this->deposit($user, $course, 5000);

        $cheapHalf = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 4000,
            'block_half' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $cheapHalf->id])
            ->assertRedirect(route('student.dashboard'));

        $order = Payment::where('user_id', $user->id)->where('tariff', 'block_1_h1')->firstOrFail();
        $this->assertSame('paid', $order->status);
        $this->assertEquals(0.0, (float) $order->amount);
        $this->assertEquals(4000.0, (float) $order->deposit_credit_applied);

        $deposit->refresh();
        $this->assertEquals(4000.0, (float) $deposit->consumed_amount);
        $this->assertNull($deposit->deposit_consumed_at, 'Частично зачтённый депозит не должен гаситься целиком.');

        // Остаток 1000 зачитывается в следующую покупку на том же курсе.
        $secondHalf = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 4000,
            'block_half' => 2,
        ]);
        $this->assertEquals(1000.0, $secondHalf->prepaidCreditForUser($user));
        $this->assertEquals(3000.0, $secondHalf->calculateFinalPriceForUser($user));
    }

    /** @test */
    public function deposit_fully_used_is_stamped_consumed(): void
    {
        // Покупка дороже депозита: депозит выбирается целиком и гасится штампом.
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $deposit = $this->deposit($user, $course, 2000);

        $tariff = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 5000,
        ]);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id]);

        $order = Payment::where('user_id', $user->id)->where('tariff', 'block_1')->firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertEquals(3000.0, (float) $order->amount);
        $this->assertEquals(2000.0, (float) $order->deposit_credit_applied);

        // Депозит гасится только при реальной оплате (webhook → paid), не при pending.
        $deposit->refresh();
        $this->assertNull($deposit->deposit_consumed_at);
        $this->assertNull($deposit->consumed_amount);

        $order->update(['status' => 'paid']);

        $deposit->refresh();
        $this->assertEquals(2000.0, (float) $deposit->consumed_amount);
        $this->assertNotNull($deposit->deposit_consumed_at);
    }

    /** @test */
    public function upgrade_credit_includes_deposit_part_of_half_block(): void
    {
        // H071 #9: блок 10000, половина 5000, депозит 2000. Раньше суммарный кэш
        // за блок выходил 2000+3000+7000 = 12000 (переплата = депозит). Теперь
        // зачёт при докупке видит полную стоимость половины (3000 кэш + 2000
        // депозит) → доплата за целый блок 5000, итого ровно 10000.
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->deposit($user, $course, 2000);

        $half = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 5000,
            'block_half' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $half->id]);

        $halfOrder = Payment::where('user_id', $user->id)->where('tariff', 'block_1_h1')->firstOrFail();
        $this->assertEquals(3000.0, (float) $halfOrder->amount);
        $halfOrder->update(['status' => 'paid']);

        $whole = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 10000,
        ]);

        $this->assertEquals(5000.0, $whole->upgradeCreditForUser($user->fresh()));
        $this->assertEquals(5000.0, $whole->calculateFinalPriceForUser($user->fresh()));
    }

    /** @test */
    public function half_fully_covered_by_deposit_still_gives_upgrade_credit(): void
    {
        // Половина, полностью покрытая депозитом (amount=0), всё равно даёт зачёт
        // при докупке целого блока — раньше отсекалась условием amount > 0.
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->deposit($user, $course, 5000);

        $half = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 5000,
            'block_half' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $half->id])
            ->assertRedirect(route('student.dashboard'));

        $whole = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 10000,
        ]);

        $this->assertEquals(5000.0, $whole->upgradeCreditForUser($user->fresh()));
        $this->assertEquals(5000.0, $whole->calculateFinalPriceForUser($user->fresh()));
    }

    /** @test */
    public function legacy_payment_without_marker_consumes_deposits_fully(): void
    {
        // Платёж без deposit_credit_applied (создан до колонки / не через чекаут)
        // гасит депозиты целиком — прежнее поведение сохранено.
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $deposit = $this->deposit($user, $course, 3000);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        $deposit->refresh();
        $this->assertNotNull($deposit->deposit_consumed_at);
        $this->assertEquals(3000.0, (float) $deposit->consumed_amount);
    }

    /** @test */
    public function partial_consumption_drains_oldest_deposit_first(): void
    {
        // Два депозита: списание идёт с более раннего; второй остаётся целым.
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $first = $this->deposit($user, $course, 1000);
        $first->updateQuietly(['created_at' => now()->subDay()]);
        $second = $this->deposit($user, $course, 1000);

        $tariff = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 1500,
            'block_half' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect(route('student.dashboard'));

        $first->refresh();
        $second->refresh();

        $this->assertNotNull($first->deposit_consumed_at, 'Ранний депозит выбран целиком.');
        $this->assertEquals(1000.0, (float) $first->consumed_amount);
        $this->assertNull($second->deposit_consumed_at);
        $this->assertEquals(500.0, (float) $second->consumed_amount);

        // Суммарный остаток 500 виден следующей покупке.
        $next = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 1500,
            'block_half' => 2,
        ]);
        $this->assertEquals(500.0, $next->prepaidCreditForUser($user->fresh()));
    }
}
