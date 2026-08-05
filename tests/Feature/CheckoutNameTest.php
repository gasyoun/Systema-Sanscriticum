<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckoutNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();

        // Точка не дёргается по-настоящему — отдаём фейковую платёжную ссылку.
        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/abc',
                    'paymentLinkId' => 'tochka_tx_001',
                ],
            ], 200),
        ]);
    }

    private function tariff(): Tariff
    {
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        return Tariff::factory()->for($course)->create(['price' => 5000]);
    }

    /** @test */
    public function guest_checkout_glues_surname_name_city_into_name(): void
    {
        $tariff = $this->tariff();

        $this->post(route('payment.create'), [
            'tariff_id' => $tariff->id,
            'name' => 'Иван',
            'surname' => 'Иванов',
            'city' => 'Москва',
            'email' => 'ivan@example.test',
            'wants_announcements' => '1',
        ])->assertRedirect('https://pay.tochka.com/redirect/abc');

        $user = User::where('email', 'ivan@example.test')->firstOrFail();
        $this->assertSame('Иванов Иван, Москва', $user->name);
        // Каст boolean объявлен в User::casts(), но Laravel 10 этот метод не применяет —
        // значение хранится как int 1/0, поэтому сверяем напрямую в БД.
        $this->assertDatabaseHas('users', [
            'email' => 'ivan@example.test',
            'wants_email_announcements' => 1,
        ]);
    }

    /** @test */
    public function surname_and_city_are_required_for_guests(): void
    {
        $tariff = $this->tariff();

        $this->post(route('payment.create'), [
            'tariff_id' => $tariff->id,
            'name' => 'Иван',
            'email' => 'ivan@example.test',
        ])->assertSessionHasErrors(['surname', 'city']);

        $this->assertDatabaseMissing('users', ['email' => 'ivan@example.test']);
    }

    /** @test */
    public function unchecked_consent_stores_false(): void
    {
        $tariff = $this->tariff();

        $this->post(route('payment.create'), [
            'tariff_id' => $tariff->id,
            'name' => 'Пётр',
            'surname' => 'Петров',
            'city' => 'Казань',
            'email' => 'petr@example.test',
            // wants_announcements не передан → чекбокс не отмечен
        ])->assertRedirect('https://pay.tochka.com/redirect/abc');

        $this->assertDatabaseHas('users', [
            'email' => 'petr@example.test',
            'wants_email_announcements' => 0,
        ]);
    }

    /** @test */
    public function guest_checkout_on_existing_email_is_rejected(): void
    {
        $tariff = $this->tariff();

        $existing = User::factory()->create([
            'name' => 'Старое Имя',
            'email' => 'old@example.test',
        ]);

        // Гость, указавший СУЩЕСТВУЮЩИЙ email, отклоняется (анти-takeover): раньше
        // платёж молча создавался на чужой аккаунт, а первая оплата слала владельцу
        // письмо со сбросом пароля. Нужно войти в кабинет и оформить оттуда.
        $this->post(route('payment.create'), [
            'tariff_id' => $tariff->id,
            'name' => 'Новое',
            'surname' => 'Фамилия',
            'city' => 'Сочи',
            'email' => 'old@example.test',
            'wants_announcements' => '1',
        ])->assertSessionHasErrors('email');

        // Ни платежа на чужой аккаунт, ни перезаписи имени.
        $this->assertDatabaseMissing('payments', ['user_id' => $existing->id]);
        $this->assertSame('Старое Имя', $existing->fresh()->name);
    }
}
