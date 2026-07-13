<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\AccessAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Пишет записи «проблем со входом» в единую ленту (H849) и — для сигналов
 * «застрял» (троттл / lockout) — проактивно пингует админов в Telegram с
 * inline-кнопкой «Разблокировать». Дедупликация алертов через кэш, чтобы серия
 * попыток одного человека не превратилась в спам админу.
 */
class AccessAttemptLogger
{
    /** Типы, по которым человек ЯВНО застрял — будим админа сразу. */
    private const ALERT_KINDS = [
        AccessAttempt::KIND_LOCKOUT,
        AccessAttempt::KIND_RESET_THROTTLED,
    ];

    /** Сколько неудачных логинов подряд (окно ниже) = «застрял», пора будить. */
    private const FAILED_LOGIN_ALERT_THRESHOLD = 3;

    private const FAILED_LOGIN_WINDOW_MINUTES = 15;

    /** Не чаще одного алерта на один email/IP в этом окне (секунды). */
    private const ALERT_DEDUPE_TTL = 600;

    public function __construct(private TelegramAdminNotifier $notifier) {}

    public function record(
        string $kind,
        ?string $email = null,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $reason = null,
    ): AccessAttempt {
        $email = $email !== null ? mb_strtolower(trim($email)) : null;

        if ($userId === null && $email !== null && $email !== '') {
            $userId = User::where('email', $email)->value('id');
        }

        $attempt = AccessAttempt::create([
            'user_id' => $userId,
            'email' => $email,
            'kind' => $kind,
            'reason' => $reason,
            'ip' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 1000) : null,
        ]);

        if ($this->shouldAlert($attempt)) {
            $this->maybeAlert($attempt);
        }

        return $attempt;
    }

    private function shouldAlert(AccessAttempt $attempt): bool
    {
        if (in_array($attempt->kind, self::ALERT_KINDS, true)) {
            return true;
        }

        // Серия неудачных логинов известного студента — тоже «застрял».
        if ($attempt->kind === AccessAttempt::KIND_FAILED_LOGIN
            && $attempt->user_id !== null
            && $attempt->email !== null
        ) {
            $recentFailures = AccessAttempt::query()
                ->where('email', $attempt->email)
                ->where('kind', AccessAttempt::KIND_FAILED_LOGIN)
                ->where('created_at', '>=', now()->subMinutes(self::FAILED_LOGIN_WINDOW_MINUTES))
                ->count();

            return $recentFailures >= self::FAILED_LOGIN_ALERT_THRESHOLD;
        }

        return false;
    }

    private function maybeAlert(AccessAttempt $attempt): void
    {
        $dedupeKey = 'access-alert:'.md5(($attempt->email ?? '').'|'.($attempt->ip ?? ''));
        if (! Cache::add($dedupeKey, true, self::ALERT_DEDUPE_TTL)) {
            return; // уже слали недавно — молчим
        }

        $emailText = $attempt->email ? htmlspecialchars($attempt->email, ENT_QUOTES, 'UTF-8') : '—';
        $known = $attempt->user_id ? '✅ есть аккаунт' : '❓ аккаунт не найден';

        $text = "🔒 <b>Студент не может войти</b>\n"
            ."Email: <code>{$emailText}</code>\n"
            ."Причина: {$attempt->kindLabel()}\n"
            ."Аккаунт: {$known}";

        // callback_data ограничена 64 байтами — шлём короткий id записи.
        $keyboard = $attempt->user_id
            ? [[['text' => '🔓 Выслать ссылку для входа', 'callback_data' => 'ub:'.$attempt->id]]]
            : null;

        $this->notifier->notifyAdmins($text, $keyboard);
    }
}
