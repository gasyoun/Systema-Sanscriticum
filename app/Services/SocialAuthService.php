<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SocialEmailNotVerifiedException;
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
     *
     * Автолинковка к СУЩЕСТВУЮЩЕМУ аккаунту по email происходит только если
     * провайдер ПОДТВЕРДИЛ владение email ($emailVerified). Иначе (напр. VK/Yandex
     * с редактируемым email) это классический account takeover — отклоняем.
     * Для нового аккаунта с неподтверждённым email логин-email не занимаем
     * (ставим stub), чтобы нельзя было «застолбить» чужой адрес — реальный email
     * сохраняем только на строке SocialAccount.
     *
     * @throws SocialEmailNotVerifiedException
     */
    public function findOrCreateUser(
        string $provider,
        string $providerId,
        ?string $email,
        ?string $name,
        bool $emailVerified = false,
    ): User {
        $existing = SocialAccount::where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if ($existing) {
            return $existing->user;
        }

        $matched = filled($email) ? User::where('email', User::normalizeEmail($email))->first() : null;

        if ($matched !== null && ! $emailVerified) {
            // Email принадлежит другому аккаунту, а провайдер не подтвердил владение.
            throw new SocialEmailNotVerifiedException($email);
        }

        $user = $matched;

        if (! $user) {
            // Реальный email в поле аккаунта — только если он подтверждён; иначе stub,
            // чтобы неподтверждённый вход не занимал чужой адрес в пространстве логинов.
            $accountEmail = ($emailVerified && filled($email))
                ? $email
                : $provider.'_'.$providerId.'@social.local';

            $user = User::create([
                'name' => $name ?: 'Студент',
                'email' => $accountEmail,
                'password' => Hash::make(Str::random(32)),
            ]);

            // A1: только для реально новых аккаунтов — существующий (matched по
            // email) уже прошёл собственную атрибуцию при своей регистрации.
            app(AttributionService::class)->applyToNewUser($user);
        }

        $user->socialAccounts()->create([
            'provider' => $provider,
            'provider_id' => $providerId,
            'email' => $email,
        ]);

        return $user;
    }
}
