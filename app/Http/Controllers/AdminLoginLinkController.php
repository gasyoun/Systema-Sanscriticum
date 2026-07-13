<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MagicLinkToken;
use App\Services\Access\StudentUnblockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Вход по ссылке, которую админ выдал студенту при разблокировке (H849).
 * Отдельно от newsletter-magic: НЕ завязано на фича-флаг рассылки и принимает
 * только токены назначения admin_unblock. Одноразовая, короткий TTL,
 * hashed-at-rest — те же гарантии, что у сброса пароля. Невалид/протух/использован
 * → 404 (без enumeration).
 */
class AdminLoginLinkController extends Controller
{
    public function login(string $token): RedirectResponse
    {
        $link = MagicLinkToken::findActive($token, StudentUnblockService::MAGIC_PURPOSE);

        abort_if($link === null, 404);

        // Атомарно гасим — проигравший гонку/replay получит 404.
        abort_unless($link->consume(), 404);

        Auth::login($link->user, remember: true);

        return redirect()->route('student.dashboard')
            ->with('status', 'С возвращением! Вы вошли в кабинет. Задайте новый пароль в настройках профиля.');
    }
}
