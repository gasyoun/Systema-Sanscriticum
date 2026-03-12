<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
}
