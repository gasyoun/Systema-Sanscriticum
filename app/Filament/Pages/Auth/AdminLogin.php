<?php

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Pages\Auth\Login;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Guest-facing Filament login (H3674 / #2176). Stock panel used APP_LOCALE=en
 * chrome («Laravel» / «Sign in»). Auth itself is unchanged. Field labels
 * are RU too (H3674 follow-up): APP_LOCALE stays en.
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

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->label('Электронная почта');
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->label('Пароль');
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->label('Запомнить меня');
    }
}
