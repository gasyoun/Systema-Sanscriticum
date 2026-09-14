<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AttributionService;
use App\Services\Membership\FreeTierLessonGranter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * H3643 — guest email+password /register → Free-tier, no payment.
 * H3692 — optional signup_source + birth_year via AttributionService.
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
            'birth_year' => ['nullable', 'integer'],
            'signup_source' => ['nullable', 'string', Rule::in(AttributionService::SIGNUP_SOURCES)],
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

        $attribution = app(AttributionService::class);
        $attribution->applyToNewUser($user);
        if ($request->filled('birth_year')) {
            $attribution->applyBirthYear($user, $request->input('birth_year'));
        }
        if ($request->filled('signup_source')) {
            $attribution->applySignupSource($user, $request->input('signup_source'));
        }

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
