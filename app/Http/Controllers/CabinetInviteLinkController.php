<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Console\Commands\SendCabinetInvites;
use App\Models\MagicLinkToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Вход по ссылке-приглашению в кабинет (H4966), выданной SendCabinetInvites.
 * Раньше все каналы (включая email) слали ссылку СБРОСА ПАРОЛЯ — брокер
 * `config('auth.passwords.users.expire')` живёт 60 минут, из-за чего 84.4%
 * из 269 приглашённых через email никогда не входили: письмо прочли позже.
 * Эта ссылка — отдельный `MagicLinkToken` назначения `cabinet_invite`
 * (см. {@see SendCabinetInvites::INVITE_PURPOSE}) с многодневным TTL, теми же
 * гарантиями, что у admin_unblock/tg_login: одноразовая, hashed-at-rest,
 * 404 без деталей на невалид/протух/использован (анти-enumeration).
 */
class CabinetInviteLinkController extends Controller
{
    public function login(string $token): RedirectResponse
    {
        $link = MagicLinkToken::findActive($token, SendCabinetInvites::INVITE_PURPOSE);

        abort_if($link === null, 404);

        // Атомарно гасим — проигравший гонку/replay получит 404.
        abort_unless($link->consume(), 404);

        Auth::login($link->user, remember: true);

        return redirect()->route('student.dashboard')
            ->with('status', 'Добро пожаловать в кабинет! Задайте пароль в настройках профиля.');
    }
}
