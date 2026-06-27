<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

/**
 * Повторное приглашение в личный кабинет для «спящих» оплативших студентов:
 * те, кто покупал курсы, но НИ РАЗУ не заходил (`last_login_at` пуст). Исходное
 * письмо с паролем многие не заметили — часть ушла в спам.
 *
 * Канал: предпочитаем Telegram (если привязан) — он надёжнее email, который и
 * попадал в спам. Иначе — письмо со ссылкой на вход (сброс пароля).
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

    protected $description = 'Пригласить в кабинет спящих оплативших студентов (никогда не логинились)';

    public function handle(): int
    {
        $send = (bool) $this->option('send');
        $limit = max(1, (int) $this->option('limit'));
        $resend = (bool) $this->option('resend');

        $query = User::query()
            ->whereNull('last_login_at')                       // ни разу не заходил
            ->where('email', '<>', '')
            ->where('email', 'not like', '%@no-email.com')      // реальный адрес
            ->whereHas('payments', fn ($q) => $q->paid());      // покупал курсы

        if (! $resend) {
            $query->whereNull('cabinet_invite_sent_at');        // ещё не приглашали
        }

        $total = (clone $query)->count();
        $batch = $query->orderBy('id')->limit($limit)->get();

        $withTg = $batch->whereNotNull('telegram_id')->count();
        $withEmail = $batch->count() - $withTg;

        $this->info("Спящих оплативших (подходящих): {$total}. В этом батче: {$batch->count()} (TG: {$withTg}, email: {$withEmail}).");

        if (! $send) {
            $this->comment('Сухой прогон. Запустите с --send, чтобы разослать (батч ограничен --limit).');
            foreach ($batch->take(10) as $u) {
                $ch = $u->telegram_id ? 'TG' : 'email';
                $this->line("  #{$u->id} {$u->email} → {$ch}");
            }

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        foreach ($batch as $user) {
            try {
                if ($user->telegram_id) {
                    $this->sendViaTelegram($user);
                } else {
                    Password::sendResetLink(['email' => $user->email]);
                }
                $user->forceFill(['cabinet_invite_sent_at' => now()])->save();
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  #{$user->id} {$user->email}: {$e->getMessage()}");
            }
        }

        $this->info("Отправлено: {$sent}. Ошибок: {$failed}. Осталось спящих: ".max(0, $total - $sent).'.');

        return self::SUCCESS;
    }

    /**
     * Ссылка для входа через Telegram: генерим токен сброса и шлём дружелюбное
     * сообщение со ссылкой — надёжнее письма (которое уходит в спам).
     */
    private function sendViaTelegram(User $user): void
    {
        $token = Password::broker()->createToken($user);
        $url = route('password.reset', $token).'?email='.urlencode($user->email);

        $text = "🙏 Вы покупали курсы в Обществе ревнителей санскрита, но ещё не заходили в личный кабинет.\n\n"
            ."В кабинете — все ваши курсы, записи занятий и материалы. Войдите по ссылке (задайте пароль):\n{$url}\n\n"
            .'Ссылка одноразовая. Если возникнут вопросы — просто ответьте на это сообщение.';

        $user->sendTelegramMessage($text);
    }
}
