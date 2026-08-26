<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H3576 §1 — тариф с ценой 0 ₽ (курс открытых стримов-анонсов ОРС): чекаут
 * обязан открыть доступ мгновенно, БЕЗ обращения в Точку. Ветка нулевой цены
 * была покрыта только через 100%-промокод; прямой сценарий бесплатного тарифа
 * — то, что реально живёт на проде (tariff 5043 курса 400).
 */
class FreeTariffCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        // Точка не должна получить ни одного запроса — ветка нулевой цены
        // обязана сработать до провайдера.
        Http::fake([
            'enter.tochka.com/*' => Http::response([], 500),
        ]);
    }

    private function freeTariff(): Tariff
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Open streams group', 'status' => 'forming']);
        $course->groups()->attach($group->id);

        return Tariff::factory()->for($course)->create([
            'title' => 'Бесплатно — стрим-анонс и запись',
            'type' => 'block',
            'block_number' => 1,
            'price' => 0,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function zero_price_tariff_grants_paid_access_without_tochka(): void
    {
        $user = User::factory()->create();
        $tariff = $this->freeTariff();

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect(route('student.dashboard'))
            ->assertSessionHas('success');

        $payment = Payment::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('paid', $payment->status);
        $this->assertEquals(0, (float) $payment->amount);
        $this->assertNull($payment->transaction_id);

        Http::assertNothingSent();
    }

    /** @test */
    public function guest_zero_price_checkout_still_opens_access_and_asks_to_log_in(): void
    {
        $tariff = $this->freeTariff();

        // Гость проходит упрощённую регистрацию; провижинер может сразу
        // аутентифицировать его, поэтому финальный редирект — login ИЛИ кабинет.
        $response = $this->post(route('payment.create'), [
            'tariff_id' => $tariff->id,
            'name' => 'Тест',
            'surname' => 'Гостевой',
            'city' => 'Обнинск',
            'email' => 'guest-free@example.test',
        ])->assertRedirect()->assertSessionHas('success');

        $payment = Payment::where('amount', 0)->firstOrFail();
        $this->assertSame('paid', $payment->status);

        Http::assertNothingSent();
    }
}
