<?php

namespace App\Http\Controllers;

use App\Models\AccessAttempt;
use App\Services\Access\AccessAttemptLogger;
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
            // Лента «проблем со входом» (H849): человек пытается войти, но его
            // email не в базе (опечатка / другой адрес) — застрявший кандидат.
            app(AccessAttemptLogger::class)->record(
                AccessAttempt::KIND_RESET_NOT_FOUND,
                email: $email,
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            // Явно сообщаем: не нашли — и подсказываем, что делать дальше.
            return back()
                ->withInput($request->only('email'))
                ->with('email_not_found', $email);
        }

        $status = Password::sendResetLink(['email' => $email]);

        // Лента H849: логируем исход запроса восстановления. RESET_THROTTLED —
        // ровно сигнал «застрял» (письмо не дошло, жмёт снова), по нему бот
        // проактивно пингует админа.
        app(AccessAttemptLogger::class)->record(
            $status === Password::RESET_THROTTLED
                ? AccessAttempt::KIND_RESET_THROTTLED
                : AccessAttempt::KIND_RESET_SENT,
            email: $email,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        // RESET_THROTTLED означает, что ссылку МЫ уже создали и отправили меньше
        // минуты назад (per-email троттл брокера, config/auth.php → passwords.throttle),
        // а НЕ то, что студент «перебирает». Прежняя красная формулировка «Слишком
        // много попыток» путала: письмо часто просто оседает в «Спаме» или
        // задерживается, студент жмёт «отправить» снова и на фактически первой
        // попытке получает упрёк. Поэтому показываем тот же зелёный блок «мы уже
        // отправили ссылку — проверьте почту и „Спам“», что и при успешной
        // отправке (email_found), — он честно описывает состояние и подсказывает
        // подождать/написать куратору, если письмо не пришло.
        if ($status === Password::RESET_THROTTLED) {
            return back()->with('email_found', $email);
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
