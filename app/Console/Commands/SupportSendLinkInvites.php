<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramSupportContact;
use App\Services\Support\SupportDmLinkInvite;
use Illuminate\Console\Command;

/**
 * H3542: бэкфилл приглашений о связывании для сейчас-незалинкованных свежих
 * контактов rusamskrtam-класса. --dry печатает только знаменатель и счёт
 * «ушло бы приглашений»; реальный прогон ограничен --limit и идемпотентен
 * через cooldown link_invited_at.
 */
class SupportSendLinkInvites extends Command
{
    protected $signature = 'support:send-link-invites
        {--dry : только census и счёт «пригласили бы», без отправки}
        {--limit=50 : потолок приглашений за прогон}
        {--days=30 : окно свежести активности чата, дней}';

    protected $description = 'H3542: разослать приглашения связать Telegram с кабинетом незалинкованным недавним контактам';

    public function handle(SupportDmLinkInvite $invites): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry');

        $census = $invites->census($days);

        $this->info("Знаменатель: незалинкованные контакты (private, активность <= {$days} дн.): {$census['unlinked_recent']}");
        $this->info("Из них на аккаунтах с auto_reply_enabled: {$census['unlinked_recent_gated']}");

        if ($census['unlinked_recent'] === 0) {
            return self::SUCCESS;
        }

        if (! $invites->isEnabled()) {
            $this->warn('Флаг features.support_dm_link_invite выключен — отправки не будет.');

            return self::SUCCESS;
        }

        $candidates = TelegramSupportContact::query()
            ->whereNull('linked_user_id')
            ->whereHas('chat', fn ($q) => $q
                ->where('type', 'private')
                ->where('last_message_at', '>=', now()->subDays($days)))
            ->orderBy('id')
            ->get();

        $invited = 0;
        $skipped = ['cooldown' => 0, 'already_linked' => 0, 'account_gate' => 0, 'not_private' => 0, 'flag_off' => 0];

        foreach ($candidates as $contact) {
            if ($invited >= $limit) {
                break;
            }

            $result = $dry
                ? $this->wouldSend($invites, $contact)
                : $invites->send($contact);

            if (($result['sent'] ?? false)) {
                $invited++;
                $this->line("Приглашение: контакт #{$contact->id} (чат {$contact->chat?->telegram_chat_id})");

                continue;
            }

            $reason = (string) ($result['reason'] ?? 'unknown');
            $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
        }

        $mode = $dry ? 'DRY' : 'ОТПРАВЛЕНО';
        $this->info("[{$mode}] пригласили бы: {$invited}".($dry ? '' : '')."; лимит прогона: {$limit}");
        foreach ($skipped as $reason => $count) {
            if ($count > 0) {
                $this->line("  пропущено ({$reason}): {$count}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Для --dry: тот же гейт, но без записи. Переиспользует cooldown-проверку
     * сервиса; пер-аккаунтный гейт считает по тем же правилам, что send().
     *
     * @return array{sent: bool, reason: string}
     */
    private function wouldSend(SupportDmLinkInvite $invites, TelegramSupportContact $contact): array
    {
        if (! $invites->isEnabled()) {
            return ['sent' => false, 'reason' => 'flag_off'];
        }

        if ($contact->linked_user_id !== null) {
            return ['sent' => false, 'reason' => 'already_linked'];
        }

        if ($invites->invitedWithinCooldown($contact)) {
            return ['sent' => false, 'reason' => 'cooldown'];
        }

        return ['sent' => true, 'reason' => 'sent'];
    }
}
