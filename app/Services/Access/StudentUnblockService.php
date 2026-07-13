<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\AccessAttempt;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * «Разблокировать студента одним кликом» (H849). У приложения НЕТ флага «бан»:
 * войти мешает только IP-троттл (throttle:5,1), который и так спадает за минуту.
 * Реально спасает застрявшего не снятие троттла, а рабочая ССЫЛКА ДЛЯ ВХОДА,
 * которую админ передаёт студенту (в т.ч. в Telegram), минуя сломанную почту.
 * Поэтому «разблокировка» здесь = снять троттл + выдать magic-ссылку (+ опц.
 * сбросить пароль), а не флипнуть колонку в БД.
 */
class StudentUnblockService
{
    /** Назначение magic-токена, выданного админом (отделяет от newsletter-ссылок). */
    public const MAGIC_PURPOSE = 'admin_unblock';

    /**
     * @return array{login_link:string, password:?string, cleared_ips:list<string>}
     */
    public function unblock(User $user, ?int $adminUserId = null, bool $resetPassword = false): array
    {
        $clearedIps = $this->clearThrottle($user);

        $token = MagicLinkToken::issueFor($user, self::MAGIC_PURPOSE, MagicLinkToken::DEFAULT_TTL_MINUTES);
        $loginLink = url('/login-link/'.$token);

        $password = null;
        if ($resetPassword) {
            $password = Str::random(10);
            $user->forceFill(['password' => Hash::make($password)])->save();
        }

        $this->markHandled($user, $adminUserId, $resetPassword ? 'unblock+reset' : 'unblock');

        return [
            'login_link' => $loginLink,
            'password' => $password,
            'cleared_ips' => $clearedIps,
        ];
    }

    /**
     * Снимает IP-троттл для всех IP, с которых студент недавно пытался войти.
     * Ключ повторяет сигнатуру Laravel ThrottleRequests для НЕаутентифицированного
     * запроса: sha1($routeDomain.'|'.$ip); у наших login/reset-маршрутов домена
     * нет → sha1('|'.$ip), и один ключ на IP покрывает все throttle:5,1-маршруты.
     * Best-effort: если внутренняя сигнатура Laravel изменится, magic-ссылка всё
     * равно даёт вход независимо от троттла.
     *
     * @return list<string> IP, для которых ключ был очищен
     */
    public function clearThrottle(User $user): array
    {
        $ips = AccessAttempt::query()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('email', mb_strtolower((string) $user->email));
            })
            ->whereNotNull('ip')
            ->recent(30)
            ->distinct()
            ->pluck('ip')
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($ips as $ip) {
            RateLimiter::clear(sha1('|'.$ip));
        }

        return $ips;
    }

    /**
     * Помечает все свежие неразобранные записи этого студента как разобранные —
     * чтобы лента «застрявших» очистилась после разблокировки.
     */
    private function markHandled(User $user, ?int $adminUserId, string $action): void
    {
        AccessAttempt::query()
            ->unhandled()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('email', mb_strtolower((string) $user->email));
            })
            ->update([
                'handled_at' => now(),
                'handled_by_user_id' => $adminUserId,
                'handled_action' => $action,
            ]);
    }
}
