<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * /login: вкладки «Войти / Регистрация» в тёмной карточке (поверх отзывов).
 * Регистрация с карточки шлёт имя, фамилию, телефон, город и ОДИН пароль в тот же
 * GuestRegisterController; /register по-прежнему шлёт пароль дважды.
 * Вкладки — только при features.guest_registration (на проде OFF).
 */
class LoginRegisterCardTest extends TestCase
{
    use RefreshDatabase;

    private function enableOn(): void
    {
        config()->set('features.guest_registration', true);
        config()->set('features.club_membership', true);
        config()->set('features.membership_tiered', true);
        config()->set('features.membership_advanced_features', true);
        config()->set('membership.club.course_slug', 'club');
    }

    private function seedTestimonials(): void
    {
        foreach (['Анна', 'Борис', 'Вера'] as $name) {
            Testimonial::create(['author_name' => $name, 'body' => 'Отличный курс!', 'is_visible' => true]);
        }
    }

    private function cardPayload(array $overrides = []): array
    {
        return array_merge([
            'form' => 'register',
            'first_name' => 'Мария',
            'last_name' => 'Иванова',
            'phone' => '+7 900 123-45-67',
            'email' => 'Maria@Example.com',
            'city' => 'Казань',
            'password' => 'secret123',
        ], $overrides);
    }

    public function test_tabs_hidden_when_guest_registration_is_off(): void
    {
        config()->set('features.guest_registration', false);
        $this->seedTestimonials();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('id="login-form"', false)
            ->assertDontSee('role="tablist"', false)
            ->assertDontSee('id="lt-register-form"', false);
    }

    public function test_tabs_and_register_card_shown_when_flag_on(): void
    {
        $this->enableOn();
        $this->seedTestimonials();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('id="lt-register-form"', false)
            ->assertSee('name="first_name"', false)
            ->assertSee('name="last_name"', false)
            ->assertSee('name="phone"', false)
            ->assertSee('name="city"', false);
    }

    public function test_card_registration_saves_name_phone_city_without_confirmation(): void
    {
        $this->enableOn();

        $this->post(route('register.post'), $this->cardPayload())
            ->assertRedirect(route('student.dashboard'));

        $user = User::where('email', 'maria@example.com')->firstOrFail();
        $this->assertSame('Мария Иванова', $user->name);
        $this->assertSame('+7 900 123-45-67', $user->phone);
        $this->assertSame('Казань', $user->city);
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, Payment::count());
    }

    public function test_bad_phone_returns_to_login_register_tab_with_russian_error(): void
    {
        $this->enableOn();
        $this->seedTestimonials();

        $this->from(route('login'))
            ->post(route('register.post'), $this->cardPayload(['phone' => 'abc']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['phone' => 'Телефон: только цифры, пробелы, «+», скобки и дефис.']);

        $this->assertSame(0, User::count());

        // Возврат открывает вкладку регистрации с сохранёнными полями.
        $this->get(route('login'))
            ->assertSee('data-active="register"', false)
            ->assertSee('value="Казань"', false);
    }

    public function test_register_page_still_checks_confirmation_when_sent(): void
    {
        $this->enableOn();

        $this->post(route('register.post'), [
            'email' => 'guest@example.com',
            'password' => 'password1',
            'password_confirmation' => 'different1',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_without_names_falls_back_to_email_local_part(): void
    {
        $this->enableOn();

        $this->post(route('register.post'), [
            'email' => 'olga@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ])->assertRedirect();

        $this->assertSame('olga', User::where('email', 'olga@example.com')->value('name'));
    }
}
