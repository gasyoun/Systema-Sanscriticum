<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    // Показать страницу входа
    public function showLoginForm()
    {
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

        // Попытка входа
        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            // Если это Админ -> в админку
            $user = Auth::user();
            if ($user->email === 'pe4kinsmart@gmail.com' || $user->is_admin) {
                return redirect()->intended('/admin');
            }

            // Если Студент -> в кабинет
            return redirect()->intended('/cabinet');
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
        'email'    => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    if (! Auth::attempt($credentials, $request->boolean('remember'))) {
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
        'user'    => [
            'name'  => $user->name,
            'email' => $user->email,
        ],
    ]);
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
