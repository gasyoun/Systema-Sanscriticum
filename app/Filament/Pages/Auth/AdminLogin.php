<?php

namespace App\Filament\Pages\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\Login;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Guest-facing Filament login (H3674 / #2176). Stock panel used APP_LOCALE=en
 * chrome («Laravel» / «Sign in»). Auth itself is unchanged. Field labels
 * are RU too (H3674 follow-up). Failed-login copy and «Forgot password?»
 * too: APP_LOCALE stays en.
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
        $href = route('password.request');

        return parent::getPasswordFormComponent()
            ->label('Пароль')
            ->hint(new HtmlString(Blade::render(
                '<x-filament::link href="'.e($href).'" tabindex="3">Забыли пароль?</x-filament::link>'
            )));
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.email' => 'Неверный email или пароль.',
        ]);
    }

    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        return Notification::make()
            ->title('Слишком много попыток входа')
            ->body('Подождите '.$exception->secondsUntilAvailable.' сек. и попробуйте снова.')
            ->danger();
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->label('Запомнить меня');
    }
}
