<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\CourseDebtChatRemoval;
use App\Models\Group;
use App\Models\TelegramSupportContact;
use App\Models\User;
use App\Services\Access\TelegramAdminNotifier;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Заявка в чат группы (chat_join_request к @zapisi_ORSbot), 20-10-2026.
 *
 * Одобряем сами, только если Telegram-аккаунт заявителя — студент с
 * активным членством в группе этого чата (членство выдаёт оплата,
 * grantAccess) и он не снят из этого чата за долг без восстановления.
 * Аккаунт ищем по users.telegram_id, затем по контакту лички школы
 * (telegram_support_contacts.linked_user_id) — студентка, писавшая в личку,
 * часто не привязывала кабинетный бот.
 *
 * Не подошло — НЕ отклоняем (посторонний мог просто не привязать Telegram):
 * заявка висит, кураторам один алерт на (чат, аккаунт) в сутки.
 */
final class ChatJoinRequestApprover
{
    public function __construct(
        private readonly ZapisiChatMemberService $zapisi,
        private readonly TelegramAdminNotifier $admins,
    ) {}

    /**
     * @param  array<string, mixed>  $request  update.chat_join_request
     * @return string approved | pending | skip
     */
    public function handle(array $request): string
    {
        if (! config('features.paid_chat_join_request')) {
            return 'skip';
        }

        $chatId = (string) ($request['chat']['id'] ?? '');
        $fromId = (string) ($request['from']['id'] ?? '');
        if ($chatId === '' || $fromId === '') {
            return 'skip';
        }

        $groups = Group::query()->where('telegram_chat_id', $chatId)->get();
        if ($groups->isEmpty()) {
            return 'skip';
        }

        $user = $this->eligibleUser($fromId, $chatId, $groups);

        if ($user !== null && $this->zapisi->approveJoinRequest($chatId, $fromId)) {
            Log::info('ChatJoinRequestApprover: approved', [
                'chat_id' => $chatId,
                'user_id' => $user->id,
            ]);

            return 'approved';
        }

        if (TelegramSendGuard::claimKey("join-request-alert:{$chatId}:{$fromId}", 86400)) {
            $who = trim(($request['from']['first_name'] ?? '').' '.($request['from']['last_name'] ?? ''));
            $username = isset($request['from']['username']) ? ' @'.$request['from']['username'] : '';

            $this->admins->notifyAdmins(sprintf(
                '🚪 Заявка в чат «%s» от %s%s (tg %s) не одобрена автоматически: %s. Проверьте и одобрите вручную.',
                $groups->first()->name,
                $who !== '' ? $who : 'без имени',
                $username,
                $fromId,
                $user === null ? 'нет оплаченного членства в группе или Telegram не привязан' : 'Telegram отказал в одобрении',
            ));
        }

        return 'pending';
    }

    /** @param  Collection<int, Group>  $groups */
    private function eligibleUser(string $telegramId, string $chatId, Collection $groups): ?User
    {
        $groupIds = $groups->pluck('id')->all();

        $userIds = User::query()->where('telegram_id', $telegramId)->pluck('id')
            ->merge(TelegramSupportContact::query()
                ->where('telegram_user_id', $telegramId)
                ->whereNotNull('linked_user_id')
                ->pluck('linked_user_id'))
            ->unique()
            ->values();

        foreach ($userIds as $userId) {
            $user = User::query()->find($userId);
            if ($user === null || ! $user->activeGroups()->whereIn('groups.id', $groupIds)->exists()) {
                continue;
            }

            $removed = CourseDebtChatRemoval::query()
                ->where('user_id', $user->id)
                ->where('telegram_chat_id', $chatId)
                ->whereNotNull('removed_at')
                ->whereNull('restored_at')
                ->exists();

            if (! $removed) {
                return $user;
            }
        }

        return null;
    }
}
