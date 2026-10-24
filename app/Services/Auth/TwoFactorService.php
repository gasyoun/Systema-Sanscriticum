<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Roles;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * H6352-r5 (рулинг 7 грилла 10-10): 2FA для всех админ-ролей
 * (super_admin/admin/manager/accountant). Преподаватели и студенты не гейтятся.
 *
 * Контракт:
 *  - секрет TOTP живёт в users.two_factor_secret (каст encrypted) и попадает в БД
 *    только после подтверждения кодом из приложения;
 *  - резервные коды — 8 штук, хранятся хешами (Hash::make), одноразовые;
 *  - «прошёл 2FA в этой сессии» — session('two_factor.passed_for') = users.id;
 *  - на проде выключатель — config('security.two_factor_enforced') (env
 *    TWO_FACTOR_ENFORCED), чтобы деплой не запирал панель при инциденте.
 */
class TwoFactorService
{
    /** Секрет начатого, но ещё не подтверждённого подключения. */
    public const SESSION_ENROLLING = 'two_factor.enrolling_secret';

    /** users.id, доказавший второй фактор в этой сессии. */
    public const SESSION_PASSED = 'two_factor.passed_for';

    public const RECOVERY_COUNT = 8;

    public function enforced(): bool
    {
        return (bool) config('security.two_factor_enforced', true);
    }

    public function requiredFor(User $user): bool
    {
        return $this->enforced() && in_array($user->role, Roles::twoFactorRequired(), true);
    }

    public function enabledFor(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && filled($user->two_factor_secret);
    }

    /** Начать (или продолжить) подключение: секрет кладётся в сессию, не в БД. */
    public function beginEnrollment(Request $request): string
    {
        $secret = Totp::randomSecret();
        $request->session()->put(self::SESSION_ENROLLING, $secret);

        return $secret;
    }

    public function enrollingSecret(Request $request): ?string
    {
        $secret = $request->session()->get(self::SESSION_ENROLLING);

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * Подтвердить подключение кодом из приложения. Возвращает одноразовые
     * резервные коды (в открытом виде — показать один раз) либо null, если код
     * не подошёл.
     *
     * @return list<string>|null
     */
    public function confirmEnrollment(Request $request, User $user, string $code): ?array
    {
        $secret = $this->enrollingSecret($request);
        if ($secret === null || ! Totp::verify($secret, $code)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $request->session()->forget(self::SESSION_ENROLLING);

        return $codes;
    }

    /** Проверка второго фактора: TOTP или одноразовый резервный код. */
    public function verify(User $user, string $code): bool
    {
        $secret = (string) $user->two_factor_secret;
        if ($secret !== '' && Totp::verify($secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    public function markPassed(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_PASSED, $user->id);
        $request->session()->regenerate();
    }

    public function passed(Request $request, User $user): bool
    {
        return (int) $request->session()->get(self::SESSION_PASSED) === (int) $user->id;
    }

    /** Сброс 2FA (потерян телефон): новый вход потребует подключения заново. */
    public function reset(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_COUNT))
            ->map(fn (): string => Str::upper(Str::random(4).'-'.Str::random(4)))
            ->values()
            ->all();
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes;
        if (! is_array($hashes) || $hashes === []) {
            return false;
        }

        $candidate = Str::upper(trim($code));
        foreach ($hashes as $index => $hash) {
            if (Hash::check($candidate, (string) $hash)) {
                unset($hashes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }
}
