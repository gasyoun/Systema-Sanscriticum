<?php

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Pages\Auth\Login;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Guest-facing Filament login (H3674 / #2176). Stock panel used APP_LOCALE=en
 * chrome («Laravel» / «Sign in»). Auth itself is unchanged.
 */
class AdminLogin extends Login
{
    public function getTitle(): string|Htmlable
    {
        return 'Вход в панель';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Вход в панель школы';
    }

    protected function getAuthenticateFormAction(): Action
    {
        return Action::make('authenticate')
            ->label('Войти')
            ->submit('authenticate');
    }
}
