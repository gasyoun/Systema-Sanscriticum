<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * H6352-r5: сброс второго фактора админ-роли — телефон потерян, приложение
 * удалено. Выполняется root'ом на проде, поэтому требует явного подтверждения
 * (или --force в скрипте) и пишет след в лог: кто сбросил и кому.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'two-factor:reset
        {email : Почта пользователя}
        {--force : Без интерактивного подтверждения}';

    protected $description = 'Сбросить 2FA админ-роли (потерян телефон) — H6352-r5';

    public function handle(TwoFactorService $twoFactor): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Пользователь {$email} не найден.");

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled()) {
            $this->info("У {$email} второй фактор не включён — сбрасывать нечего.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Сбросить 2FA у {$email} ({$user->role})?")) {
            $this->warn('Отменено.');

            return self::FAILURE;
        }

        $twoFactor->reset($user);

        Log::warning('two-factor:reset — второй фактор сброшен вручную', [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'host' => gethostname() ?: null,
        ]);

        $this->info("2FA у {$email} сброшен: следующий вход заставит подключить приложение заново.");

        return self::SUCCESS;
    }
}
