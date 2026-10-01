<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MagicLinkToken;
use App\Services\Access\TelegramLoginService;
use App\Support\UsedLoginLinkResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Вход по одноразовой ссылке, выданной студент-ботом («Telegram-вход»,
 * CABINET_ADOPTION_ROADMAP P2, 28-08-2026). Принимает ТОЛЬКО токены назначения
 * tg_login — админ-ссылку (H849) или newsletter-магию в этот маршрут скормить
 * нельзя. Невалид/протух/использован → единый мягкий ответ без различения
 * случаев ({@see UsedLoginLinkResponse}), те же гарантии, что у /login-link.
 * Самогейтится флагом telegram_cabinet_login (404 при OFF).
 */
class TgLoginLinkController extends Controller
{
    public function login(string $token): RedirectResponse
    {
        abort_unless(config('features.telegram_cabinet_login'), 404);

        $link = MagicLinkToken::findActive($token, TelegramLoginService::MAGIC_PURPOSE);

        // Атомарно гасим — проигравший гонку/replay получает тот же ответ, что и
        // несуществующий токен.
        if ($link === null || ! $link->consume()) {
            return UsedLoginLinkResponse::make();
        }

        Auth::login($link->user, remember: true);

        return redirect()->route('student.dashboard')
            ->with('status', 'С возвращением! Вы вошли в кабинет через Telegram.');
    }
}
