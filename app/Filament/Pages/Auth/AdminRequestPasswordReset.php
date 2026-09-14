<?php

namespace App\Filament\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;

/**
 * Do not run a parallel Filament reset stack. School reset
 * (PasswordResetController + PasswordResetMail + AccessAttempt logging) is the
 * one path. This page exists so Panel::passwordReset() is on (login hint).
 */
class AdminRequestPasswordReset extends RequestPasswordReset
{
    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        $this->redirect(route('password.request'));
    }
}
