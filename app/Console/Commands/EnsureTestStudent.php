<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Smoke-студент (role=null / student) для cabinet:probe — не manager/admin.
 *
 *   php artisan users:ensure-test-student
 *
 * Без TEST_STUDENT_PASSWORD — no-op.
 */
class EnsureTestStudent extends Command
{
    protected $signature = 'users:ensure-test-student
        {--show-email : напечатать email (пароль никогда не печатается)}';

    protected $description = 'Создать/обновить smoke-студента из TEST_STUDENT_EMAIL / TEST_STUDENT_PASSWORD';

    public function handle(): int
    {
        if (\PHP_OS_FAMILY === 'Windows' && \function_exists('sapi_windows_cp_set')) {
            @sapi_windows_cp_set(65001);
        }

        $email = User::normalizeEmail((string) config('services.test_student.email', ''));
        $password = (string) config('services.test_student.password', '');
        $name = (string) config('services.test_student.name', 'Smoke Student');

        if ($password === '') {
            $this->comment('TEST_STUDENT_PASSWORD пуст — smoke-студент не создан (no-op).');

            return self::SUCCESS;
        }

        if ($email === '') {
            $this->error('TEST_STUDENT_EMAIL пуст, а пароль задан.');

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            // Только «чистый» студент: role null/empty, не staff.
            $role = $existing->role;
            $isStudent = $role === null || $role === '' || $role === 'student';
            if (! $isStudent) {
                $this->error(sprintf(
                    'Email %s занят role=%s — smoke-студент не перезаписывает staff.',
                    $email,
                    $role ?? 'null',
                ));

                return self::FAILURE;
            }
        }

        $user = $existing ?? new User;
        $user->forceFill([
            'email' => $email,
            'name' => $name,
            'password' => Hash::make($password),
            'role' => null,
            'is_admin' => false,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->info(sprintf(
            'Smoke-студент готов: %s (id=%d, role=student/null).',
            $user->email,
            $user->id,
        ));

        if ($this->option('show-email')) {
            $this->line($user->email);
        }

        $this->comment('Пароль только в TEST_STUDENT_PASSWORD (.env).');

        return self::SUCCESS;
    }
}
