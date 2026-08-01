<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Управление участниками учебных чатов через @zapisi_ORSbot.
 *
 * Бот должен быть администратором группы с can_restrict_members.
 * Исключение = hard ban (banChatMember без unban): вернуться по инвайту
 * нельзя, пока оператор не вызовет unban.
 *
 * UI: дашборд «Записи (бот)» (roster) + «Должники» (по users.telegram_id
 * и group.telegram_chat_id активных групп). LMS-членство / course_user
 * при кике не меняется.
 */
class ZapisiChatMemberService
{
    public function token(): string
    {
        return (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '';
    }

    /**
     * Активные учебные группы студента с заполненным telegram_chat_id.
     * Опционально — только группы, привязанные к курсу (строка должника).
     *
     * @return Collection<int, Group>
     */
    public function studyGroupsWithChat(User $user, ?int $courseId = null): Collection
    {
        $query = $user->activeGroups()
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '');

        if ($courseId !== null && $courseId > 0) {
            $query->whereHas('courses', fn ($q) => $q->where('courses.id', $courseId));
        }

        return $query->get()
            ->unique(fn (Group $g): string => trim((string) $g->telegram_chat_id))
            ->values();
    }

    /**
     * Hard-ban студента из всех (или выбранных) учебных TG-чатов.
     *
     * @param  list<int>|null  $groupIds  null = все studyGroupsWithChat; иначе пересечение
     * @return array{
     *     ok: list<array{group_id: int, group_name: string, chat_id: string}>,
     *     failed: list<array{group_id: int, group_name: string, chat_id: string, error: string}>,
     *     skipped: ?string
     * }
     */
    public function kickFromStudyChats(
        User $user,
        ?int $courseId = null,
        ?int $byUserId = null,
        ?array $groupIds = null,
    ): array {
        $telegramId = trim((string) ($user->telegram_id ?? ''));
        if ($telegramId === '') {
            return [
                'ok' => [],
                'failed' => [],
                'skipped' => 'У студента не привязан Telegram (users.telegram_id пуст).',
            ];
        }

        if (! $this->isConfigured()) {
            return [
                'ok' => [],
                'failed' => [],
                'skipped' => 'Токен @zapisi_ORSbot не задан (MarketingSetting).',
            ];
        }

        $groups = $this->studyGroupsWithChat($user, $courseId);
        if ($groupIds !== null) {
            $want = array_map('intval', $groupIds);
            $groups = $groups->filter(fn (Group $g): bool => in_array((int) $g->id, $want, true))->values();
        }

        if ($groups->isEmpty()) {
            return [
                'ok' => [],
                'failed' => [],
                'skipped' => $courseId
                    ? 'Нет активных групп курса с telegram_chat_id (проверьте карточку группы).'
                    : 'Нет активных групп с telegram_chat_id.',
            ];
        }

        $ok = [];
        $failed = [];

        foreach ($groups as $group) {
            $chatId = trim((string) $group->telegram_chat_id);
            $row = [
                'group_id' => (int) $group->id,
                'group_name' => (string) $group->name,
                'chat_id' => $chatId,
            ];
            try {
                $this->kick($chatId, $telegramId, $byUserId);
                $ok[] = $row;
            } catch (ZapisiChatMemberException $e) {
                $failed[] = $row + ['error' => $e->getMessage()];
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'skipped' => null];
    }

    /**
     * @return array{ok: bool, status: ?string, can_restrict: bool, detail: string}
     */
    public function botRightsInChat(string $chatId): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'status' => null,
                'can_restrict' => false,
                'detail' => 'Токен @zapisi_ORSbot не задан (MarketingSetting).',
            ];
        }

        if (trim($chatId) === '') {
            return [
                'ok' => false,
                'status' => null,
                'can_restrict' => false,
                'detail' => 'У группы не заполнен telegram_chat_id.',
            ];
        }

        $me = $this->api('getMe');
        $botId = $me['result']['id'] ?? null;
        if (! $botId) {
            return [
                'ok' => false,
                'status' => null,
                'can_restrict' => false,
                'detail' => 'Не удалось вызвать getMe для zapisi-бота.',
            ];
        }

        $member = $this->api('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $botId,
        ]);

        if (! ($member['ok'] ?? false)) {
            $desc = (string) ($member['description'] ?? 'chat not found');

            return [
                'ok' => false,
                'status' => null,
                'can_restrict' => false,
                'detail' => "Бот не видит чат {$chatId}: {$desc}. Добавьте @zapisi_ORSbot в группу.",
            ];
        }

        $status = (string) ($member['result']['status'] ?? '');
        $canRestrict = (bool) ($member['result']['can_restrict_members'] ?? false);
        // Creator always can; admin needs the flag.
        if ($status === 'creator') {
            $canRestrict = true;
        }

        $ok = in_array($status, ['administrator', 'creator'], true) && $canRestrict;

        return [
            'ok' => $ok,
            'status' => $status,
            'can_restrict' => $canRestrict,
            'detail' => $ok
                ? "Бот — {$status}, can_restrict_members=да."
                : "Бот в чате как «{$status}», can_restrict_members="
                    .($canRestrict ? 'да' : 'нет')
                    .'. Нужен админ с правом ограничивать участников.',
        ];
    }

    /**
     * Hard ban: banChatMember only. User cannot rejoin via invite until unban().
     *
     * @throws ZapisiChatMemberException
     */
    public function kick(string $chatId, int|string $telegramUserId, ?int $byUserId = null): void
    {
        $chatId = trim($chatId);
        $telegramUserId = (string) $telegramUserId;

        if ($chatId === '' || $telegramUserId === '') {
            throw new ZapisiChatMemberException('Не указан chat_id или telegram user id.');
        }

        $rights = $this->botRightsInChat($chatId);
        if (! $rights['ok']) {
            throw new ZapisiChatMemberException($rights['detail']);
        }

        $ban = $this->api('banChatMember', [
            'chat_id' => $chatId,
            'user_id' => $telegramUserId,
        ]);

        if (! ($ban['ok'] ?? false)) {
            $desc = (string) ($ban['description'] ?? 'unknown');
            Log::warning('ZapisiChatMemberService: banChatMember failed', [
                'chat_id' => $chatId,
                'user_id' => $telegramUserId,
                'by' => $byUserId,
                'body' => $ban,
            ]);
            throw new ZapisiChatMemberException("Telegram banChatMember: {$desc}");
        }

        Log::info('ZapisiChatMemberService: ban ok', [
            'chat_id' => $chatId,
            'telegram_user_id' => $telegramUserId,
            'by_user_id' => $byUserId,
        ]);
    }

    /**
     * Снять бан: unbanChatMember. После этого можно снова войти по инвайту.
     *
     * @throws ZapisiChatMemberException
     */
    public function unban(string $chatId, int|string $telegramUserId, ?int $byUserId = null): void
    {
        $chatId = trim($chatId);
        $telegramUserId = (string) $telegramUserId;

        if ($chatId === '' || $telegramUserId === '') {
            throw new ZapisiChatMemberException('Не указан chat_id или telegram user id.');
        }

        $rights = $this->botRightsInChat($chatId);
        if (! $rights['ok']) {
            throw new ZapisiChatMemberException($rights['detail']);
        }

        $unban = $this->api('unbanChatMember', [
            'chat_id' => $chatId,
            'user_id' => $telegramUserId,
            'only_if_banned' => true,
        ]);

        if (! ($unban['ok'] ?? false)) {
            $desc = (string) ($unban['description'] ?? 'unknown');
            Log::warning('ZapisiChatMemberService: unbanChatMember failed', [
                'chat_id' => $chatId,
                'user_id' => $telegramUserId,
                'by' => $byUserId,
                'body' => $unban,
            ]);
            throw new ZapisiChatMemberException("Telegram unbanChatMember: {$desc}");
        }

        Log::info('ZapisiChatMemberService: unban ok', [
            'chat_id' => $chatId,
            'telegram_user_id' => $telegramUserId,
            'by_user_id' => $byUserId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function api(string $method, array $params = []): array
    {
        $token = $this->token();
        if ($token === '') {
            return ['ok' => false, 'description' => 'empty token'];
        }

        try {
            $response = Http::timeout(20)->post(
                "https://api.telegram.org/bot{$token}/{$method}",
                $params,
            );

            $json = $response->json();

            return is_array($json) ? $json : ['ok' => false, 'description' => $response->body()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }
}
