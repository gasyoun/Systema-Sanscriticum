<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\LoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

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

        $email = $credentials['email'];
        $ip = (string) $request->ip();

        // H3314 — per-credential lockout поверх IP-throttle роута: 6-я попытка
        // брутфорса одной учётки ловит 429 даже с другого IP.
        if (LoginThrottle::tooManyAttempts($email, $ip)) {
            LoginThrottle::fireLockout($request);

            throw ValidationException::withMessages([
                'email' => [LoginThrottle::LOCKOUT_MESSAGE],
            ]);
        }

        // Один lookup вместо exists()+value(): нужен и для H1949 remember-гварда,
        // и для равного времени ответа на несуществующем email.
        $knownUser = User::where('email', $email)->first();

        // H1949 — opt-in long-lived cookie; default off when checkbox absent.
        // Admins never get remember: Filament shares the web guard and
        // SESSION_LIFETIME was capped at 1 day for that reason (28-07-2026) —
        // a weeks-long recaller would re-auth admin after idle expiry.
        $remember = $request->boolean('remember')
            && ! (bool) ($knownUser->is_admin ?? false);

        if (Auth::attempt($credentials, $remember)) {
            LoginThrottle::clear($email, $ip);
            $request->session()->regenerate();

            // Если это Админ -> в админку
            $user = Auth::user();
            if ($user->is_admin) {
                return redirect()->intended('/admin');
            }

            // Если Студент -> в кабинет
            return redirect()->intended(route('student.dashboard'));
        }

        // Если пароль не подошел — счётчик по email и по email|ip вверх;
        // на неизвестном email дожигаем bcrypt-цикл, чтобы время ответа не
        // раскрывало существование адреса.
        LoginThrottle::hit($email, $ip);
        if (! $knownUser) {
            LoginThrottle::equalizeTiming((string) $request->input('password'));
        }

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

        $email = $credentials['email'];
        $ip = (string) $request->ip();

        // H3314 — тот же per-credential lockout, что и в /login, но JSON-ответом.
        if (LoginThrottle::tooManyAttempts($email, $ip)) {
            LoginThrottle::fireLockout($request);

            return response()->json([
                'success' => false,
                'message' => LoginThrottle::LOCKOUT_MESSAGE,
            ], 429);
        }

        $knownUser = User::where('email', $email)->first();

        // Same admin guard as login(): shop is student-facing, but still refuse
        // a long-lived cookie if an admin account is used here by mistake.
        $remember = $request->boolean('remember')
            && ! (bool) ($knownUser->is_admin ?? false);

        if (! Auth::attempt($credentials, $remember)) {
            LoginThrottle::hit($email, $ip);
            if (! $knownUser) {
                LoginThrottle::equalizeTiming((string) $request->input('password'));
            }

            return response()->json([
                'success' => false,
                'message' => 'Неверный email или пароль.',
            ], 422);
        }

        LoginThrottle::clear($email, $ip);
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
