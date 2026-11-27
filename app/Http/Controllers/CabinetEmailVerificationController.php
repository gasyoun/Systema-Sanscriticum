<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MagicLinkToken;
use App\Services\Access\CabinetEmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Одна ссылка из письма: гасит токен, ставит email_verified_at и логинит.
 * Вход через бота по-прежнему работает без подтверждения (мягкий режим).
 */
class CabinetEmailVerificationController extends Controller
{
    public function __construct(private CabinetEmailVerification $verification) {}

    public function verify(string $token): RedirectResponse
    {
        abort_unless($this->verification->enabled(), 404);

        $link = MagicLinkToken::findActive($token, CabinetEmailVerification::PURPOSE);
        abort_if($link === null, 404);
        abort_unless($link->consume(), 404);

        $user = $link->user;
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        Auth::login($user, remember: true);

        return redirect()->route('student.dashboard')
            ->with('status', 'Почта подтверждена. Добро пожаловать в кабинет!');
    }
}
