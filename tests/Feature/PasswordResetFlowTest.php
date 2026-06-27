<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_reset_mail_with_link(): void
    {
        Mail::fake();

        $user = User::create([
            'name' => 'Тест Студент',
            'email' => 'reset@example.com',
            'password' => Hash::make('oldpassword'),
        ]);

        // Самопроверка: найденный email явно подтверждается (email_found) +
        // ссылка для входа отправляется. (Раньше был нейтральный 'status'.)
        $response = $this->post('/forgot-password', ['email' => $user->email]);
        $response->assertSessionHas('email_found', 'reset@example.com');

        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user) {
            return $mail->user->is($user)
                && str_contains($mail->resetUrl, '/reset-password/')
                && str_contains($mail->resetUrl, urlencode($user->email));
        });
    }

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = User::create([
            'name' => 'Тест Студент',
            'email' => 'reset2@example.com',
            'password' => Hash::make('oldpassword'),
        ]);

        $token = Password::createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
    }

    public function test_logged_in_student_can_change_password(): void
    {
        $user = User::create([
            'name' => 'Тест Студент',
            'email' => 'change@example.com',
            'password' => Hash::make('currentpass'),
        ]);

        $response = $this->actingAs($user)->post('/profile/password', [
            'current_password' => 'currentpass',
            'password' => 'brandnewpass1',
            'password_confirmation' => 'brandnewpass1',
        ]);

        $response->assertSessionHas('password_status');
        $this->assertTrue(Hash::check('brandnewpass1', $user->fresh()->password));
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::create([
            'name' => 'Тест Студент',
            'email' => 'change2@example.com',
            'password' => Hash::make('currentpass'),
        ]);

        $response = $this->actingAs($user)->post('/profile/password', [
            'current_password' => 'wrongpass',
            'password' => 'brandnewpass1',
            'password_confirmation' => 'brandnewpass1',
        ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('currentpass', $user->fresh()->password));
    }
}
