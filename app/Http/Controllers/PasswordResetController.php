<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    // Форма запроса ссылки (ввод email)
    public function showRequestForm()
    {
        return view('auth.passwords.email');
    }

    // Отправка письма со ссылкой на сброс
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($request->only('email'));

        // Не раскрываем, существует ли email: при любом исходе показываем
        // нейтральное сообщение (кроме явного троттлинга).
        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors(['email' => 'Слишком много попыток. Подождите минуту и попробуйте снова.']);
        }

        return back()->with('status', 'Если такой email зарегистрирован, мы отправили на него ссылку для восстановления пароля. Проверьте почту (и папку «Спам»).');
    }

    // Форма ввода нового пароля (по ссылке из письма)
    public function showResetForm(Request $request, string $token)
    {
        return view('auth.passwords.reset', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    // Сохранение нового пароля
    public function reset(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', 'Пароль обновлён. Теперь войдите с новым паролем.');
        }

        return back()->withInput($request->only('email'))->withErrors([
            'email' => 'Ссылка недействительна или устарела. Запросите восстановление пароля заново.',
        ]);
    }
}
