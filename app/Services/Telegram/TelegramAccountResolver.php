<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramSupportContact;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Telegram-аккаунт → учётки Systema. Сначала users.telegram_id, затем контакт
 * лички школы (telegram_support_contacts.linked_user_id): студентка, писавшая
 * в личку, часто не привязывала кабинетный бот.
 *
 * Один путь резолва на одобрение заявки в чат (ChatJoinRequestApprover) и на
 * правило исключения из чата (ChatSilenceRemovalEligibility) — чтобы «кто это
 * в чате» не расходилось между входом и выходом.
 */
final class TelegramAccountResolver
{
    /** @return Collection<int, int> */
    public function userIds(string $telegramUserId): Collection
    {
        $telegramUserId = trim($telegramUserId);
        if ($telegramUserId === '') {
            return collect();
        }

        return User::query()->where('telegram_id', $telegramUserId)->pluck('id')
            ->merge(TelegramSupportContact::query()
                ->where('telegram_user_id', $telegramUserId)
                ->whereNotNull('linked_user_id')
                ->pluck('linked_user_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }
}
