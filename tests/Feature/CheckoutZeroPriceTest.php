<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Бесплатная запись на курс (тариф 0 ₽): залогиненный пользователь без
 * доступа видит ФОРМУ оформления (не заглушку «уже есть доступ»), после
 * отправки — paid-платёж, группы курса и запись в кабинет. Юзер с уже
 * оплаченным доступом видит короткий путь «Перейти в личный кабинет».
 *
 * Регресс: блок «У вас уже есть доступ» рендерился каждому залогиненному
 * на нулевом тарифе — платёж не создавался, grantAccess не запускался
 * (мини-курс «Подготовительная группа», 10-10-2026).
 */
class CheckoutZeroPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Course $course;

    private Tariff $tariff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $group = Group::create(['name' => 'G-zero-checkout']);
        $this->course = Course::factory()->create(['is_active' => true, 'is_visible' => true]);
        $this->course->groups()->attach($group);

        $this->tariff = Tariff::create([
            'course_id' => $this->course->id,
            'title' => 'Бесплатно',
            'type' => 'full',
            'price' => 0,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function logged_in_user_without_access_sees_the_form_not_shortcut(): void
    {
        $this->actingAs($this->user)
            ->get(route('checkout.show', $this->tariff))
            ->assertOk()
            ->assertSee('payment/create', false)
            ->assertDontSee('У вас уже есть доступ');
    }

    /** @test */
    public function zero_price_submit_creates_paid_payment_and_grants_group(): void
    {
        $this->actingAs($this->user)
            ->post(route('payment.create'), ['tariff_id' => $this->tariff->id])
            ->assertRedirect(route('student.dashboard'));

        $payment = Payment::where('user_id', $this->user->id)
            ->where('course_id', $this->course->id)
            ->where('tariff', 'full')
            ->first();

        $this->assertNotNull($payment);
        $this->assertSame('paid', $payment->status);
        $this->assertEquals(0.0, (float) $payment->amount);

        // grantAccess: юзер в группе курса (вход на страницу курса в кабинете).
        $this->assertTrue(
            $this->user->fresh()->groups->pluck('id')
                ->intersect($this->course->groups()->pluck('groups.id'))
                ->isNotEmpty(),
            'Пользователь должен попасть в группу курса.',
        );
    }

    /** @test */
    public function user_with_paid_access_sees_the_shortcut(): void
    {
        Payment::create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'tariff' => 'full',
            'amount' => 0,
            'status' => 'paid',
        ]);

        $this->actingAs($this->user)
            ->get(route('checkout.show', $this->tariff))
            ->assertOk()
            ->assertSee('У вас уже есть доступ')
            ->assertSee(route('student.dashboard'));
    }
}
