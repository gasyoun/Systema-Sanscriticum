<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Логика социальной авторизации, НЕ завязанная на Socialite (контроллер сам
 * получает профиль у провайдера и передаёт сюда поля). Так ядро (поиск/создание
 * и привязка аккаунта) тестируется без сети и без OAuth-секретов.
 */
class SocialAuthService
{
    /** Провайдеры, которые в принципе поддерживаются. */
    public const SUPPORTED = ['google', 'vkontakte', 'yandex'];

    /**
     * Провайдеры, реально включённые (задан client_id в config/services).
     *
     * @return list<string>
     */
    public static function enabledProviders(): array
    {
        return array_values(array_filter(
            self::SUPPORTED,
            fn (string $p): bool => filled(config("services.{$p}.client_id")),
        ));
    }

    public static function isEnabled(string $provider): bool
    {
        return in_array($provider, self::enabledProviders(), true);
    }

    /**
     * Найти или создать пользователя по данным внешнего аккаунта и привязать его.
     *
     * Порядок: уже привязанный social-аккаунт → пользователь по совпадающему
     * email → новый пользователь. Во всех случаях гарантируем строку SocialAccount.
     */
    public function findOrCreateUser(string $provider, string $providerId, ?string $email, ?string $name): User
    {
        $existing = SocialAccount::where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if ($existing) {
            return $existing->user;
        }

        $user = filled($email) ? User::where('email', $email)->first() : null;

        if (! $user) {
            $user = User::create([
                'name' => $name ?: 'Студент',
                // Уникальная заглушка, если провайдер не отдал email.
                'email' => $email ?: $provider.'_'.$providerId.'@social.local',
                'password' => Hash::make(Str::random(32)),
            ]);
        }

        $user->socialAccounts()->create([
            'provider' => $provider,
            'provider_id' => $providerId,
            'email' => $email,
        ]);

        return $user;
    }
}
