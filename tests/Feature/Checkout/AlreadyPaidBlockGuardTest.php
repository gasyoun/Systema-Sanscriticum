<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Checkout\AlreadyPaidBlockGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 16-11-2026: студентка открыла сохранённые ссылки прошлой оплаты
 * (/checkout/<блок 12>, /checkout/<блок 3>) и второй раз заплатила за уже
 * оплаченные блоки вместо 13 и 4. Чекаут целого блока теперь это ловит.
 */
class AlreadyPaidBlockGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();
        config(['features.checkout_already_paid_block_guard' => true]);

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/abc',
                    'paymentLinkId' => 'tochka_tx_001',
                ],
            ], 200),
        ]);
    }

    /**
     * Курс с тарифами блоков 11–13, студент оплатил 11 и 12.
     *
     * @return array{user: User, course: Course, tariffs: array<int, Tariff>}
     */
    private function paidThrough12(): array
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'G-already-paid']);
        $course->groups()->attach($group->id);

        $tariffs = [];
        foreach ([11, 12, 13] as $n) {
            $tariffs[$n] = Tariff::factory()->block($n)->create([
                'course_id' => $course->id, 'price' => 8000, 'is_active' => true,
            ]);
        }

        foreach ([11, 12] as $n) {
            Payment::create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'amount' => 8000, 'tariff' => "block_{$n}", 'status' => 'paid',
                'start_block' => $n, 'end_block' => $n, 'is_conditional' => false,
            ]);
        }

        return compact('user', 'course', 'tariffs');
    }

    /** @test */
    public function checkout_of_paid_block_shows_notice_and_next_block_button(): void
    {
        ['user' => $user, 'tariffs' => $t] = $this->paidThrough12();

        $this->actingAs($user)
            ->get(route('checkout.show', $t[12]))
            ->assertOk()
            ->assertSee('Блок №12 уже оплачен')
            ->assertSee(route('checkout.show', $t[13]), false)
            ->assertSee('Оплатить блок №13')
            ->assertDontSee('id="checkout-form"', false);
    }

    /** @test */
    public function payment_create_for_paid_block_is_refused_before_payment_and_bank(): void
    {
        ['user' => $user, 'tariffs' => $t] = $this->paidThrough12();

        $this->actingAs($user)
            ->post(route('payment.create'), ['oferta_accepted' => '1', 'pd_consent' => '1', 'tariff_id' => $t[12]->id])
            ->assertSessionHasErrors('tariff_id');

        $this->assertSame(2, Payment::where('user_id', $user->id)->count());
        Http::assertNothingSent();
    }

    /** @test */
    public function unpaid_next_block_checkout_works_as_before(): void
    {
        ['user' => $user, 'tariffs' => $t] = $this->paidThrough12();

        $this->actingAs($user)
            ->get(route('checkout.show', $t[13]))
            ->assertOk()
            ->assertSee('id="checkout-form"', false)
            ->assertDontSee('уже оплачен');

        $this->actingAs($user)
            ->post(route('payment.create'), ['oferta_accepted' => '1', 'pd_consent' => '1', 'tariff_id' => $t[13]->id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id, 'tariff' => 'block_13', 'status' => 'pending',
        ]);
    }

    /** @test */
    public function flag_off_keeps_old_behaviour(): void
    {
        config(['features.checkout_already_paid_block_guard' => false]);
        ['user' => $user, 'tariffs' => $t] = $this->paidThrough12();

        $this->actingAs($user)
            ->get(route('checkout.show', $t[12]))
            ->assertOk()
            ->assertSee('id="checkout-form"', false);

        $this->assertNull(app(AlreadyPaidBlockGuard::class)->check($user, $t[12]));
    }

    /** @test */
    public function half_payment_does_not_block_whole_block_and_half_tariffs_are_ignored(): void
    {
        ['user' => $user, 'course' => $course, 'tariffs' => $t] = $this->paidThrough12();

        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_13_h1', 'status' => 'paid',
            'start_block' => 13, 'end_block' => 13, 'is_conditional' => false,
        ]);
        $half = Tariff::create([
            'course_id' => $course->id, 'title' => 'Блок 12 (вторая половина)',
            'type' => 'block', 'block_number' => 12, 'block_half' => 2,
            'price' => 4000, 'is_active' => true,
        ]);

        $guard = app(AlreadyPaidBlockGuard::class);
        $this->assertNull($guard->check($user, $t[13]));
        $this->assertNull($guard->check($user, $half));
    }

    /** @test */
    public function no_next_button_when_all_later_blocks_are_paid_or_missing(): void
    {
        ['user' => $user, 'course' => $course, 'tariffs' => $t] = $this->paidThrough12();
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 8000, 'tariff' => 'block_13', 'status' => 'paid',
            'start_block' => 13, 'end_block' => 13, 'is_conditional' => false,
        ]);

        $result = app(AlreadyPaidBlockGuard::class)->check($user, $t[12]);

        $this->assertSame(12, $result['block']);
        $this->assertNull($result['next']);
    }

    /** @test */
    public function guests_and_failed_or_conditional_payments_do_not_trigger(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->block(5)->create(['course_id' => $course->id, 'is_active' => true]);

        foreach ([['status' => 'failed', 'is_conditional' => false], ['status' => 'paid', 'is_conditional' => true]] as $row) {
            Payment::create(array_merge([
                'user_id' => $user->id, 'course_id' => $course->id,
                'amount' => 4800, 'tariff' => 'block_5', 'start_block' => 5, 'end_block' => 5,
            ], $row));
        }

        $guard = app(AlreadyPaidBlockGuard::class);
        $this->assertNull($guard->check($user, $tariff));
        $this->assertNull($guard->check(null, $tariff));
    }
}
