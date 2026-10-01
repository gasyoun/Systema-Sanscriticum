<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MagicLinkToken;
use App\Services\Access\StudentUnblockService;
use App\Support\UsedLoginLinkResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Вход по ссылке, которую админ выдал студенту при разблокировке (H849).
 * Отдельно от newsletter-magic: НЕ завязано на фича-флаг рассылки и принимает
 * только токены назначения admin_unblock. Одноразовая, короткий TTL,
 * hashed-at-rest — те же гарантии, что у сброса пароля. Невалид/протух/использован
 * → единый мягкий ответ без различения случаев ({@see UsedLoginLinkResponse}).
 */
class AdminLoginLinkController extends Controller
{
    public function login(string $token): RedirectResponse
    {
        $link = MagicLinkToken::findActive($token, StudentUnblockService::MAGIC_PURPOSE);

        // Атомарно гасим — проигравший гонку/replay получает тот же ответ, что и
        // несуществующий токен.
        if ($link === null || ! $link->consume()) {
            return UsedLoginLinkResponse::make();
        }

        Auth::login($link->user, remember: true);

        return redirect()->route('student.dashboard')
            ->with('status', 'С возвращением! Вы вошли в кабинет. Задайте новый пароль в настройках профиля.');
    }
}
