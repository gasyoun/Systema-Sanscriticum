<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\MarketingSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Управление участниками учебных чатов через @zapisi_ORSbot.
 *
 * Бот должен быть администратором группы с can_restrict_members.
 * Soft kick = banChatMember + unbanChatMember (можно вернуться по инвайту).
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
     * Soft kick: ban then unban (user can rejoin via invite link).
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

        $unban = $this->api('unbanChatMember', [
            'chat_id' => $chatId,
            'user_id' => $telegramUserId,
            'only_if_banned' => true,
        ]);

        if (! ($unban['ok'] ?? false)) {
            $desc = (string) ($unban['description'] ?? 'unknown');
            Log::warning('ZapisiChatMemberService: unbanChatMember failed after ban', [
                'chat_id' => $chatId,
                'user_id' => $telegramUserId,
                'by' => $byUserId,
                'body' => $unban,
            ]);
            throw new ZapisiChatMemberException(
                "Участник заблокирован, но unban не прошёл ({$desc}). Разблокируйте вручную в Telegram."
            );
        }

        Log::info('ZapisiChatMemberService: kick ok', [
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
