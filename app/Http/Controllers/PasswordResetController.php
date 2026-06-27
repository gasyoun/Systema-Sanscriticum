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

    // Самопроверка email + отправка ссылки для входа.
    //
    // ОСОЗНАННЫЙ ВЫБОР ВЛАДЕЛЬЦА (онбординг старых студентов): в отличие от
    // обычной анти-enumeration-практики, здесь МЫ ЯВНО говорим, найден ли email
    // в базе. Цель — убрать промежуточный шаг «напишите нам в Telegram, мы
    // проверим почту»: студент сам узнаёт, есть ли его адрес (тот, на который
    // оформлял заказ), и сразу получает ссылку для входа. Это критично, когда
    // Telegram в РФ работает плохо. Эндпоинт жёстко троттлится (throttle:5,1)
    // для защиты от массового перебора.
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = \App\Models\User::normalizeEmail($request->input('email'));

        // Плейсхолдер-адреса студентов без почты не считаем «найденными».
        $exists = $email !== ''
            && ! str_ends_with($email, '@no-email.com')
            && \App\Models\User::where('email', $email)->exists();

        if (! $exists) {
            // Явно сообщаем: не нашли — и подсказываем, что делать дальше.
            return back()
                ->withInput($request->only('email'))
                ->with('email_not_found', $email);
        }

        $status = Password::sendResetLink(['email' => $email]);

        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors(['email' => 'Слишком много попыток. Подождите минуту и попробуйте снова.']);
        }

        // Нашли — ссылка для входа отправлена.
        return back()->with('email_found', $email);
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

        $request->merge(['email' => \App\Models\User::normalizeEmail($request->input('email'))]);

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
