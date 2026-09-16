<?php

namespace App\Console\Commands;

use App\Models\MagicLinkToken;
use App\Models\User;
use App\Services\Messaging\SmsRuChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Повторное приглашение в личный кабинет для студентов с выданным доступом,
 * которые НИ РАЗУ не заходили (`login_count = 0`). Популяция ровно та же, что
 * считает еженедельная сводка (см. OnboardingWeeklyDigest): «доступ выслан»
 * по штампу `[Доступ отправлен` в note + реальный email — НЕ только платившие,
 * иначе список расходится с тем, что куратор видит в дайджесте.
 *
 * Исходное письмо с паролем многие не заметили — часть ушла в спам.
 *
 * Канал (первый доступный побеждает, дальше не дублируем): Telegram → VK → SMS
 * → email (сброс пароля). Telegram/VK/SMS надежнее email, который чаще уходит
 * в спам — поэтому email на последнем месте.
 *
 * H4966: ссылка — отдельный `MagicLinkToken` назначения {@see self::INVITE_PURPOSE}
 * с TTL {@see self::INVITE_TTL_MINUTES} (7 дней), а НЕ сброс пароля (брокер живёт
 * 60 минут — 84.4% из 269 приглашённых email-веткой так и не вошли, письмо
 * прочли позже). Один и тот же одноразовый линк для всех каналов.
 *
 * REPORT-ONLY по умолчанию. Рассылка — только с --send, батчами (--limit),
 * чтобы не словить спам-флаги и не блокировать очередь.
 *
 * H4966: `cabinet_invite_sent_at` больше не постоянное исключение — без
 * --resend всё равно попадают те, кому слали больше {@see self::AUTO_RESEND_AFTER_DAYS}
 * дней назад и кто так и не зашёл (см. §4 SHIP в H4966).
 *
 *   php artisan students:send-login-invites                 # сухой прогон (кого затронет)
 *   php artisan students:send-login-invites --send --limit=100
 *   php artisan students:send-login-invites --send --resend # немедленно повторно всем, кому уже слали
 */
class SendCabinetInvites extends Command
{
    /** Назначение magic-токена ссылки-приглашения — отделяет от newsletter/admin_unblock/tg_login. */
    public const INVITE_PURPOSE = 'cabinet_invite';

    /** TTL ссылки-приглашения: дни, не 60-минутный брокер сброса пароля. */
    public const INVITE_TTL_MINUTES = 60 * 24 * 7; // 7 дней

    /**
     * H4966 SHIP §4: без --resend старая отправка перестаёт быть постоянным
     * исключением — после этого окна не зашедший снова попадает в батч.
     */
    public const AUTO_RESEND_AFTER_DAYS = 7;

    protected $signature = 'students:send-login-invites
        {--send : Реально отправить (без флага — сухой прогон)}
        {--limit=200 : Максимум приглашений за один прогон (батч)}
        {--resend : Включить немедленно тех, кому приглашение уже отправляли (игнорирует окно auto-resend)}
        {--include-no-stamp : Включить также никогда не входивших без штампа «[Доступ отправлен» (доступ существовал, но не был выслан)}';

    protected $description = 'Пригласить в кабинет студентов с выданным доступом, которые никогда не логинились';

    public function handle(): int
    {
        $send = (bool) $this->option('send');
        $limit = max(1, (int) $this->option('limit'));
        $resend = (bool) $this->option('resend');
        $includeNoStamp = (bool) $this->option('include-no-stamp');

        // Та же популяция, что и в OnboardingWeeklyDigest: «доступ выслан» +
        // ни разу не заходил (login_count=0), а не только платившие.
        $query = User::query()
            ->where('is_admin', false)
            ->where(function ($q) use ($includeNoStamp) {
                $q->where('note', 'like', '%[Доступ отправлен%');
                if ($includeNoStamp) {
                    $q->orWhereNull('note')->orWhere('note', 'not like', '%[Доступ отправлен%');
                }
            })
            ->where('login_count', 0)
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->where('email', 'not like', '%@no-email.com');      // реальный адрес

        if (! $resend) {
            // H4966: НЕ постоянное исключение — переприглашаем после окна
            // AUTO_RESEND_AFTER_DAYS тому, кто так и не зашёл.
            $query->where(function ($q) {
                $q->whereNull('cabinet_invite_sent_at')
                    ->orWhere('cabinet_invite_sent_at', '<=', now()->subDays(self::AUTO_RESEND_AFTER_DAYS));
            });
        }

        $total = (clone $query)->count();
        $batch = $query->orderBy('id')->limit($limit)->get();

        $counts = $batch->reduce(function (array $acc, User $u) {
            $acc[$this->pickChannel($u)]++;

            return $acc;
        }, ['telegram' => 0, 'vk' => 0, 'sms' => 0, 'email' => 0]);

        $this->info("Не заходивших с выданным доступом: {$total}. В этом батче: {$batch->count()} "
            ."(TG: {$counts['telegram']}, VK: {$counts['vk']}, SMS: {$counts['sms']}, email: {$counts['email']}).");

        if (! $send) {
            $this->comment('Сухой прогон. Запустите с --send, чтобы разослать (батч ограничен --limit).');
            foreach ($batch->take(10) as $u) {
                $this->line("  #{$u->id} {$u->email} → {$this->pickChannel($u)}");
            }

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        foreach ($batch as $user) {
            try {
                $ok = match ($this->pickChannel($user)) {
                    'telegram' => $this->sendViaTelegram($user),
                    'vk' => $user->sendVkMessage($this->messageText($user)),
                    'sms' => $user->sendSmsMessage($this->smsText($user)),
                    default => Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT,
                };

                if (! $ok) {
                    throw new \RuntimeException('канал отказал (см. лог)');
                }

                $user->forceFill(['cabinet_invite_sent_at' => now()])->save();
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  #{$user->id} {$user->email}: {$e->getMessage()}");
            }
        }

        $this->info("Отправлено: {$sent}. Ошибок: {$failed}. Осталось: ".max(0, $total - $sent).'.');

        return self::SUCCESS;
    }

    /** Приоритет: Telegram → VK → SMS (если настроен) → email. */
    private function pickChannel(User $user): string
    {
        if ($user->telegram_id) {
            return 'telegram';
        }
        if ($user->vk_id) {
            return 'vk';
        }
        if ($user->phone && app(SmsRuChannel::class)->isConfigured()) {
            return 'sms';
        }

        return 'email';
    }

    private function sendViaTelegram(User $user): bool
    {
        return $user->sendTelegramMessage($this->messageText($user));
    }

    /**
     * Ссылка для входа: генерим токен сброса и шлем дружелюбное сообщение со
     * ссылкой — надежнее письма (которое уходит в спам).
     */
    private function messageText(User $user): string
    {
        $url = $this->resetUrl($user);

        return "🙏 Вам открыт доступ в личный кабинет Общества ревнителей санскрита, но вы еще не заходили.\n\n"
            ."В кабинете — все ваши курсы, записи занятий и материалы. Войдите по ссылке (задайте пароль):\n{$url}\n\n"
            .'Как пользоваться кабинетом — руководство: '.rtrim((string) config('app.url'), '/').'/help/kabinet'."\n\n"
            .'Ссылка одноразовая. Если возникнут вопросы — просто ответьте на это сообщение.';
    }

    /** SMS короче — без эмодзи/форматирования, тариф идет по длине сообщения. */
    private function smsText(User $user): string
    {
        $url = $this->resetUrl($user);

        return "Общество ревнителей санскрита: вам открыт доступ в личный кабинет. Войдите по ссылке (задайте пароль): {$url}";
    }

    private function resetUrl(User $user): string
    {
        $token = Password::broker()->createToken($user);

        return route('password.reset', $token).'?email='.urlencode($user->email);
    }
}
