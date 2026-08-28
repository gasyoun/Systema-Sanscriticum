<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Membership\FreeTierLessonGranter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * H3643 — guest email+password /register → Free-tier, no payment.
 *
 * Entire surface is behind features.guest_registration (default OFF).
 * Flag OFF → 404 on GET and POST. Does not touch Tochka/webhooks.
 */
final class GuestRegisterController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $this->guardFlag();

        if (Auth::check()) {
            return redirect()->route('student.dashboard');
        }

        return view('auth.register');
    }

    public function store(Request $request, FreeTierLessonGranter $granter): RedirectResponse
    {
        $this->guardFlag();

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $email = User::normalizeEmail($validated['email']);

        $request->merge(['email' => $email]);
        $request->validate([
            'email' => ['unique:users,email'],
        ], [
            'email.unique' => 'У вас уже есть аккаунт с этим email. Войдите в кабинет.',
        ]);

        $local = explode('@', (string) $email)[0] ?: 'Студент';

        $user = User::create([
            'email' => $email,
            'name' => $local,
            'password' => Hash::make($validated['password']),
        ]);

        $granter->grantSignupFor($user);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('student.dashboard');
    }

    private function guardFlag(): void
    {
        abort_unless((bool) config('features.guest_registration', false), 404);
    }
}
