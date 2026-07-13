<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Непостоянная (не в БД) идентичность анонимного посетителя веб-чата (H536 Phase 2).
 *
 * Оборачивает session-scoped `guest_token`, чтобы приватный broadcast-канал
 * `support.conversation.{id}` авторизовал гостя ровно тем же путём, что и
 * залогиненного пользователя, — без строки в `users` и без 4-го identity-маппинга
 * (см. docs/support-identity.md). Только чтение: ничего не аутентифицирует, кроме
 * владения собственным тредом по совпадению токена.
 */
final class GuestChatUser implements Authenticatable
{
    public function __construct(public readonly string $guestToken) {}

    public function getAuthIdentifierName(): string
    {
        return 'guest_token';
    }

    public function getAuthIdentifier(): string
    {
        return $this->guestToken;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
        //
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
