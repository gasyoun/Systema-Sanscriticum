<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SocialAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

/**
 * Вход через соцсети (Socialite). Контроллер тонкий: получает профиль у
 * провайдера и делегирует привязку/создание в SocialAuthService.
 *
 * ВАЖНО: пакет laravel/socialite (и community-драйверы для VK/Yandex) должны
 * быть установлены и заданы client_id/secret в .env — иначе провайдер просто
 * не включён (кнопка не показывается, прямой заход отдаёт 404).
 */
class SocialAuthController extends Controller
{
    /** Редирект на провайдера. */
    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(SocialAuthService::isEnabled($provider), 404);

        return Socialite::driver($provider)->redirect();
    }

    /** Колбэк от провайдера: логиним и возвращаем в кабинет. */
    public function callback(string $provider, SocialAuthService $service): RedirectResponse
    {
        abort_unless(SocialAuthService::isEnabled($provider), 404);

        try {
            $oauthUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            Log::warning('Social auth callback failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return redirect()->route('login')->with('error', 'Не удалось войти через соцсеть. Попробуйте ещё раз.');
        }

        try {
            $user = $service->findOrCreateUser(
                $provider,
                (string) $oauthUser->getId(),
                $oauthUser->getEmail(),
                $oauthUser->getName() ?: $oauthUser->getNickname(),
                $this->providerVerifiedEmail($provider, $oauthUser),
            );
        } catch (\App\Exceptions\SocialEmailNotVerifiedException $e) {
            return redirect()->route('login')->with(
                'error',
                'Этот email уже привязан к аккаунту. Войдите по паролю, а соцсеть подключите из личного кабинета.'
            );
        }

        Auth::login($user, remember: true);

        return redirect()->route('student.dashboard');
    }

    /**
     * Подтвердил ли провайдер владение email. Google отдаёт email_verified.
     * Для остальных (VK/Yandex через community-драйверы) email может быть
     * задан пользователем — считаем неподтверждённым, чтобы не допустить takeover.
     */
    private function providerVerifiedEmail(string $provider, \Laravel\Socialite\Contracts\User $oauthUser): bool
    {
        if ($provider !== 'google') {
            return false;
        }

        $raw = method_exists($oauthUser, 'getRaw') ? (array) $oauthUser->getRaw() : (array) ($oauthUser->user ?? []);

        return (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false);
    }
}
