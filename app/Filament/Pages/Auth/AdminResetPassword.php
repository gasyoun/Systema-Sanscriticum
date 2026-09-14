<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\PasswordReset\ResetPassword;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Token landings go to the school reset form, not stock Filament English chrome.
 */
class AdminResetPassword extends ResetPassword
{
    public function mount(?string $email = null, ?string $token = null): void
    {
        $token = $token ?? request()->query('token');
        $email = $email ?? request()->query('email');

        $this->redirect(route('password.reset', array_filter([
            'token' => $token,
            'email' => $email,
        ])));
    }

    public function getHeading(): string|Htmlable
    {
        return 'Сброс пароля';
    }
}
