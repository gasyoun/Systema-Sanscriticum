<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H1285 — страницы возврата с оплаты. /payment/success и /payment/fail обязаны
 * РЕНДЕРИТЬ страницу (раньше оба редиректили: success — в кабинет, fail — на
 * главную, теряя курс и повтор). Страницы только читают состояние — статус
 * платежа меняет исключительно вебхук Точки, эти тесты заодно фиксируют
 * отсутствие мутаций.
 */
class PaymentReturnPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    /** Прямое создание платежа в нужном статусе, без наблюдателей. */
    private function makePayment(User $user, Course $course, string $status): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => $status,
        ]));
    }

    public function test_success_renders_confirmed_state_for_paid_payment(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);
        $this->makePayment($user, $course, 'paid');

        $response = $this->actingAs($user)->get('/payment/success');

        $response->assertOk();
        $response->assertSee('Оплата получена');
        $response->assertSee('Санскрит с нуля');
        $response->assertSee('Перейти к обучению');
    }

    public function test_success_renders_pending_state_with_bounded_wait(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->makePayment($user, $course, 'pending');

        $response = $this->actingAs($user)->get('/payment/success');

        $response->assertOk();
        $response->assertSee('Платеж принят');
        $response->assertSee('пару минут');
        $response->assertSee('Если через 10 минут доступа всё еще нет');
    }

    public function test_success_renders_login_variant_for_guest(): void
    {
        $response = $this->get('/payment/success');

        $response->assertOk();
        $response->assertSee('Войти в аккаунт');
        $response->assertSee('Если через 10 минут доступа всё еще нет');
    }

    public function test_fail_renders_double_charge_reassurance_and_course_retry(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->makePayment($user, $course, 'pending');

        $response = $this->actingAs($user)->get('/payment/fail');

        $response->assertOk();
        $response->assertSee('не платите повторно');
        $response->assertSee('Попробовать снова');
        $response->assertSee($course->slug);
    }

    public function test_fail_falls_back_to_catalog_for_guest(): void
    {
        $response = $this->get('/payment/fail');

        $response->assertOk();
        $response->assertSee('не платите повторно');
        $response->assertSee('Выбрать курс');
        $response->assertSee('/online');
    }

    public function test_fail_skips_retry_link_to_hidden_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->hidden()->create();
        $this->makePayment($user, $course, 'pending');

        $response = $this->actingAs($user)->get('/payment/fail');

        $response->assertOk();
        // Скрытый курс отдал бы 404 на повторе — ссылка уходит в каталог.
        $response->assertSee('Выбрать курс');
        $response->assertDontSee('Попробовать снова');
    }

    public function test_return_pages_do_not_mutate_payment_status(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $payment = $this->makePayment($user, $course, 'pending');

        $this->actingAs($user)->get('/payment/success');
        $this->actingAs($user)->get('/payment/fail');

        $this->assertSame('pending', $payment->fresh()->status);
    }
}
