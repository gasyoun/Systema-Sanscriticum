<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\Access\StudentUnblockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «Сменить пароль» есть в обоих кабинетах (29-09-2026): прод живёт на
 * CABINET_HYBRID, а кнопка и модалка оставались только на легаси-дашборде.
 * Студенту после входа по ссылке куратора («задайте пароль») было негде это сделать.
 */
class ChangePasswordInCabinetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{bool}> */
    public static function cabinets(): array
    {
        return ['hybrid' => [true], 'legacy' => [false]];
    }

    #[DataProvider('cabinets')]
    public function test_cabinet_home_offers_change_password(bool $hybrid): void
    {
        config(['features.cabinet_hybrid' => $hybrid]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Сменить пароль')
            ->assertSee('open-change-password', false)
            ->assertSee('data-change-password-modal', false)
            ->assertSee(route('student.password.update'), false)
            ->assertSee(route('student.password.email-link'), false)
            // Гостевая страница вошедшему недоступна — ссылки на неё в кабинете быть не должно.
            ->assertDontSee(route('password.request'), false);
    }

    public function test_guest_forgot_password_page_bounces_a_logged_in_student(): void
    {
        // Почему кнопка «Сбросить по email» ничего не делала (29-09-2026).
        $this->actingAs(User::factory()->create())
            ->get(route('password.request'))
            ->assertRedirect();
    }

    public function test_email_link_is_sent_to_the_students_own_address(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'own@example.com']);

        $this->actingAs($user)
            ->from(route('student.dashboard'))
            ->post(route('student.password.email-link'), ['email' => 'someone-else@example.com'])
            ->assertRedirect(route('student.dashboard'))
            ->assertSessionHas('password_status');

        Mail::assertQueued(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->user->is($user));
        Mail::assertQueuedCount(1);
    }

    public function test_email_link_refuses_a_placeholder_address(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'id12345@no-email.com']);

        $this->actingAs($user)
            ->from(route('student.dashboard'))
            ->post(route('student.password.email-link'))
            ->assertSessionHas('error');

        Mail::assertNothingQueued();
    }

    public function test_email_link_requires_login(): void
    {
        $this->post(route('student.password.email-link'))->assertRedirect(route('login'));
    }

    public function test_logged_in_student_can_finish_the_reset_from_the_email_link(): void
    {
        $user = User::factory()->create(['password' => Hash::make('forgotten-1')]);
        $token = Password::createToken($user);

        $this->actingAs($user)
            ->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('password.update'), [
                'token' => $token,
                'email' => $user->email,
                'password' => 'brand-new-2',
                'password_confirmation' => 'brand-new-2',
            ])
            ->assertRedirect(route('student.dashboard'))
            ->assertSessionHas('password_status');

        $this->assertTrue(Hash::check('brand-new-2', $user->fresh()->password));
    }

    public function test_reset_form_still_rejects_a_bad_token_for_a_logged_in_student(): void
    {
        $user = User::factory()->create(['password' => Hash::make('keep-me-1')]);

        $this->actingAs($user)
            ->post(route('password.update'), [
                'token' => 'not-a-real-token',
                'email' => $user->email,
                'password' => 'hijack-pass-2',
                'password_confirmation' => 'hijack-pass-2',
            ])
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('keep-me-1', $user->fresh()->password));
    }

    #[DataProvider('cabinets')]
    public function test_password_change_succeeds_and_is_confirmed_on_the_cabinet(bool $hybrid): void
    {
        config(['features.cabinet_hybrid' => $hybrid]);
        $user = User::factory()->create(['password' => Hash::make('old-password-1')]);

        $this->actingAs($user)
            ->from(route('student.dashboard'))
            ->post(route('student.password.update'), [
                'current_password' => 'old-password-1',
                'password' => 'new-password-2',
                'password_confirmation' => 'new-password-2',
            ])
            ->assertRedirect(route('student.dashboard'));

        $this->assertTrue(Hash::check('new-password-2', $user->fresh()->password));

        $this->actingAs($user->fresh())->get(route('student.dashboard'))
            ->assertSee('Пароль успешно изменён.');
    }

    public function test_hybrid_cabinet_shows_the_set_password_hint_after_an_admin_login_link(): void
    {
        config(['features.cabinet_hybrid' => true]);
        $student = User::factory()->create();
        $result = app(StudentUnblockService::class)->unblock($student, adminUserId: null);
        $parts = explode('/', $result['login_link']);

        $this->followingRedirects()
            ->get('/login-link/'.end($parts))
            ->assertOk()
            ->assertSee('Задайте новый пароль')
            ->assertSee('Сменить пароль');
    }
}
