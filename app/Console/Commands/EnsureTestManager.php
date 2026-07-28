<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Идемпотентный smoke-аккаунт role=manager для проверки входа и CRM-панели.
 *
 * Пароль только из .env (TEST_MANAGER_PASSWORD) — в репозиторий не попадает.
 * Роль всегда manager: никогда super_admin/admin (те остаются за Гасунсом и Иваном).
 *
 *   php artisan users:ensure-test-manager
 *
 * Без TEST_MANAGER_PASSWORD — no-op (exit 0): на средах без smoke-аккаунта
 * команда не шумит и не ломает deploy.
 */
class EnsureTestManager extends Command
{
    protected $signature = 'users:ensure-test-manager
        {--show-email : напечатать email (пароль никогда не печатается)}';

    protected $description = 'Создать/обновить smoke-менеджера из TEST_MANAGER_EMAIL / TEST_MANAGER_PASSWORD';

    public function handle(): int
    {
        if (\PHP_OS_FAMILY === 'Windows' && \function_exists('sapi_windows_cp_set')) {
            @sapi_windows_cp_set(65001);
        }

        $email = User::normalizeEmail((string) config('services.test_manager.email', ''));
        $password = (string) config('services.test_manager.password', '');
        $name = (string) config('services.test_manager.name', 'Smoke Manager');

        if ($password === '') {
            $this->warn('TEST_MANAGER_PASSWORD пуст — smoke-менеджер не создан (no-op).');

            return self::SUCCESS;
        }

        if ($email === '') {
            $this->error('TEST_MANAGER_EMAIL пуст, а пароль задан — укажите email в .env.');

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            // Обновляем ТОЛЬКО уже-manager. Студент (role=null), teacher, admin —
            // никогда не перезаписываем: иначе smoke-ensure молча повысит живой email.
            if ($existing->role !== Roles::MANAGER) {
                $this->error(sprintf(
                    'Email %s уже занят (role=%s). Smoke-менеджер трогает только role=manager или новый email — выберите другой TEST_MANAGER_EMAIL.',
                    $email,
                    $existing->role ?? 'null/student',
                ));

                return self::FAILURE;
            }
        }

        $user = $existing ?? new User;
        $user->forceFill([
            'email' => $email,
            'name' => $name,
            'password' => Hash::make($password),
            'role' => Roles::MANAGER,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->info(sprintf(
            'Smoke-менеджер готов: %s (id=%d, role=%s). Вход: /admin/login (Filament) или /login.',
            $user->email,
            $user->id,
            $user->role,
        ));

        if ($this->option('show-email')) {
            $this->line($user->email);
        }

        $this->comment('Пароль не печатается — он только в TEST_MANAGER_PASSWORD (.env).');

        return self::SUCCESS;
    }
}
