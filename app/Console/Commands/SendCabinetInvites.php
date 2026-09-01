<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Messaging\SmsRuChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

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
 * → email (сброс пароля). Telegram/VK/SMS надёжнее email, который чаще уходит
 * в спам — поэтому email на последнем месте.
 *
 * REPORT-ONLY по умолчанию. Рассылка — только с --send, батчами (--limit),
 * чтобы не словить спам-флаги и не блокировать очередь.
 *
 *   php artisan students:send-login-invites                 # сухой прогон (кого затронет)
 *   php artisan students:send-login-invites --send --limit=100
 *   php artisan students:send-login-invites --send --resend # повторно тем, кому уже слали
 */
class SendCabinetInvites extends Command
{
    protected $signature = 'students:send-login-invites
        {--send : Реально отправить (без флага — сухой прогон)}
        {--limit=200 : Максимум приглашений за один прогон (батч)}
        {--resend : Включить тех, кому приглашение уже отправляли}';

    protected $description = 'Пригласить в кабинет студентов с выданным доступом, которые никогда не логинились';

    public function handle(): int
    {
        $send = (bool) $this->option('send');
        $limit = max(1, (int) $this->option('limit'));
        $resend = (bool) $this->option('resend');

        // Та же популяция, что и в OnboardingWeeklyDigest: «доступ выслан» +
        // ни разу не заходил (login_count=0), а не только платившие.
        $query = User::query()
            ->where('is_admin', false)
            ->where('note', 'like', '%[Доступ отправлен%')
            ->where('login_count', 0)
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->where('email', 'not like', '%@no-email.com');      // реальный адрес

        if (! $resend) {
            $query->whereNull('cabinet_invite_sent_at');        // ещё не приглашали
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
     * Ссылка для входа: генерим токен сброса и шлём дружелюбное сообщение со
     * ссылкой — надёжнее письма (которое уходит в спам).
     */
    private function messageText(User $user): string
    {
        $url = $this->resetUrl($user);

        return "🙏 Вам открыт доступ в личный кабинет Общества ревнителей санскрита, но вы ещё не заходили.\n\n"
            ."В кабинете — все ваши курсы, записи занятий и материалы. Войдите по ссылке (задайте пароль):\n{$url}\n\n"
            .'Как пользоваться кабинетом — руководство: '.rtrim((string) config('app.url'), '/').'/docs/rukovodstvo-studenta.pdf'."\n\n"
            .'Ссылка одноразовая. Если возникнут вопросы — просто ответьте на это сообщение.';
    }

    /** SMS короче — без эмодзи/форматирования, тариф идёт по длине сообщения. */
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
