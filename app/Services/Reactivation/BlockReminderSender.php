<?php

declare(strict_types=1);

namespace App\Services\Reactivation;

use App\Models\DebtReminder;
use App\Models\User;
use App\Services\DebtorReminderDispatcher;

/**
 * Напоминание об оплате блока с экрана «Участники по блокам».
 *
 * Отличие от {@see WinBackSender}: тот работает по шаблону из библиотеки и
 * пишет «уснувшим», а здесь куратор видит конкретный блок и шлёт своё короткое
 * напоминание тем, у кого этого блока нет. Общий контракт — тот же:
 *  - дедуп 24 ч, чтобы один и тот же должник не получил два письма за день
 *    из двух разных мест админки;
 *  - рендер плейсхолдеров ({name}, {course}, {block}, {pay_link});
 *  - журнал контакта в `debt_reminders` — он же кормит правило H2746.
 */
final class BlockReminderSender
{
    /** Источник в `debt_reminders`: отличает ручную отправку от авто-команды. */
    public const SOURCE = 'block_participants';

    /** Окно дедупа, часы. */
    public const DEDUPE_HOURS = 24;

    public function __construct(
        private readonly DebtorReminderDispatcher $dispatcher,
        private readonly TelegramAccountChannel $account,
    ) {}

    /**
     * @return array{account: bool, bot: bool, email: bool}
     */
    public function channelsFor(User $user): array
    {
        return [
            'account' => $this->account->isAvailable() && $this->account->resolve($user) !== null,
            'bot' => ! empty($user->telegram_id),
            'email' => filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false,
        ];
    }

    /**
     * @return array{status: 'sent'|'skipped_recent'|'no_channels', channels: list<string>}
     */
    public function send(
        User $user,
        int $courseId,
        int $blockNumber,
        string $text,
        string $subject,
        bool $toAccount,
        bool $toBot,
        bool $toEmail,
    ): array {
        if ($this->recentlyContacted($user->id, $courseId, $blockNumber)) {
            return ['status' => 'skipped_recent', 'channels' => []];
        }

        $available = $this->channelsFor($user);
        $wanted = array_keys(array_filter([
            'account' => $toAccount && $available['account'],
            'bot' => $toBot && $available['bot'],
            'email' => $toEmail && $available['email'],
        ]));

        if ($wanted === []) {
            return ['status' => 'no_channels', 'channels' => []];
        }

        $accountError = null;
        $ok = $this->dispatcher->send(
            $user,
            $courseId,
            $blockNumber,
            $text,
            $subject,
            $toBot && $available['bot'],
            false,
            $toEmail && $available['email'],
            null,
            null,
            self::SOURCE,
            $toAccount && $available['account'],
            $accountError,
        );

        return [
            'status' => $ok ? 'sent' : 'no_channels',
            'channels' => $ok ? $wanted : [],
            // Почему не ушло от аккаунта (BUSINESS_PEER_USAGE_MISSING и т.п.) —
            // наружу, чтобы экран сказал правду, а не «отправлено: 0».
            'account_error' => $accountError,
        ];
    }

    private function recentlyContacted(int $userId, int $courseId, int $blockNumber): bool
    {
        return DebtReminder::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('block_number', $blockNumber)
            ->where('sent_at', '>=', now()->subHours(self::DEDUPE_HOURS))
            ->exists();
    }
}
