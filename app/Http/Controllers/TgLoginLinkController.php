<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MagicLinkToken;
use App\Services\Access\TelegramLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Вход по одноразовой ссылке, выданной студент-ботом («Telegram-вход»,
 * CABINET_ADOPTION_ROADMAP P2, 28-08-2026). Принимает ТОЛЬКО токены назначения
 * tg_login — админ-ссылку (H849) или newsletter-магию в этот маршрут скормить
 * нельзя. Невалид/протух/использован → 404 без деталей (анти-enumeration), те
 * же гарантии, что у /login-link. Самогейтится флагом
 * telegram_cabinet_login (404 при OFF).
 */
class TgLoginLinkController extends Controller
{
    public function login(string $token): RedirectResponse
    {
        abort_unless(config('features.telegram_cabinet_login'), 404);

        $link = MagicLinkToken::findActive($token, TelegramLoginService::MAGIC_PURPOSE);

        abort_if($link === null, 404);

        // Атомарно гасим — проигравший гонку/replay получит 404.
        abort_unless($link->consume(), 404);

        Auth::login($link->user, remember: true);

        return redirect()->route('student.dashboard')
            ->with('status', 'С возвращением! Вы вошли в кабинет через Telegram.');
    }
}
