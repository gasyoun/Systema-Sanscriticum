<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    // Показать страницу входа
    public function showLoginForm()
    {
        // Уже залогинен -> не показываем форму, а уводим в кабинет
        // (реальная смена URL: /login -> /dvaram, админ -> /admin).
        if (Auth::check()) {
            return Auth::user()->is_admin
                ? redirect('/admin')
                : redirect()->route('student.dashboard');
        }

        return view('auth.login');
    }

    // Обработать вход
    public function login(Request $request)
    {
        // Валидация
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Логин по нормализованному email: хранится lowercase+trim, поэтому и при
        // входе приводим ввод к тому же виду (иначе «Anna@Mail.ru» не найдёт аккаунт).
        $credentials['email'] = User::normalizeEmail($credentials['email']);

        // H1949 — opt-in long-lived cookie; default off when checkbox absent.
        // Admins never get remember: Filament shares the web guard and
        // SESSION_LIFETIME was capped at 1 day for that reason (28-07-2026) —
        // a weeks-long recaller would re-auth admin after idle expiry.
        $remember = $request->boolean('remember')
            && ! (bool) User::query()
                ->where('email', $credentials['email'])
                ->value('is_admin');

        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();

            // Если это Админ -> в админку
            $user = Auth::user();
            if ($user->is_admin) {
                return redirect()->intended('/admin');
            }

            // Если Студент -> в кабинет
            return redirect()->intended(route('student.dashboard'));
        }

        // Если пароль не подошел
        return back()->withErrors([
            'email' => 'Неверный email или пароль.',
        ])->onlyInput('email');
    }

    // Выход
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /**
     * AJAX-логин с витрины магазина.
     * Не редиректит, возвращает JSON. Сессия — стандартная web-guard,
     * так что auth()->check() в Blade-шаблонах начнет возвращать true после reload().
     */
    public function shopLogin(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $credentials['email'] = User::normalizeEmail($credentials['email']);

        // Same admin guard as login(): shop is student-facing, but still refuse
        // a long-lived cookie if an admin account is used here by mistake.
        $remember = $request->boolean('remember')
            && ! (bool) User::query()
                ->where('email', $credentials['email'])
                ->value('is_admin');

        if (! Auth::attempt($credentials, $remember)) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный email или пароль.',
            ], 422);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        return response()->json([
            'success' => true,
            'message' => 'Вы успешно вошли.',
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Самостоятельная смена пароля студентом из кабинета.
     * Проверяет текущий пароль, ставит новый.
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [
            'current_password.current_password' => 'Текущий пароль указан неверно.',
        ]);

        $user = $request->user();
        // H1949 — password change must kill long-lived remember cookies on every
        // device (Laravel stores the recaller hash in users.remember_token).
        $user->forceFill([
            'password' => Hash::make($request->input('password')),
            'remember_token' => Str::random(60),
        ])->save();

        return back()->with('password_status', 'Пароль успешно изменён.');
    }

    /**
     * AJAX-выход (с витрины — без редиректа на /login).
     */
    public function shopLogout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['success' => true]);
    }
}
