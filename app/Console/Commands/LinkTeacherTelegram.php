<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * H4253: привязка Telegram-id преподавателя (или staff) к панельному User —
 * обязательное условие работы TG-команд (каникулы, датированная отмена),
 * резолвящих автора через users.telegram_id / social_accounts.
 *
 * Идемпотентен: создаёт User(role=teacher, teacher_id) только если его ещё нет
 * (случайный пароль, БЕЗ письма — для письма есть TeacherAccountService),
 * затем пишет users.telegram_id и social_accounts (provider=telegram).
 *
 * Пример: php artisan teachers:link-telegram 5 471824146
 */
class LinkTeacherTelegram extends Command
{
    protected $signature = 'teachers:link-telegram {teacher : ID карточки Teacher} {telegramId : Telegram user id}';

    protected $description = 'Привязать Telegram-id к панельному пользователю преподавателя (создать User при отсутствии) — для TG-команд H4253';

    public function handle(): int
    {
        $teacher = Teacher::find((int) $this->argument('teacher'));
        if ($teacher === null) {
            $this->error('Teacher не найден.');

            return self::FAILURE;
        }

        $telegramId = (int) $this->argument('telegramId');
        if ($telegramId <= 0) {
            $this->error('Telegram id должен быть положительным числом.');

            return self::FAILURE;
        }

        /** @var User|null $user */
        $user = User::query()
            ->where('teacher_id', $teacher->id)
            ->when(filled($teacher->email), fn ($query) => $query->orWhere('email', trim((string) $teacher->email)))
            ->first();

        if ($user === null) {
            $user = User::create([
                'name' => $teacher->name,
                'email' => filled($teacher->email) ? trim((string) $teacher->email) : 'teacher'.$teacher->id.'@samskrte.ru',
                'password' => Hash::make(Str::random(16)),
                'role' => Roles::TEACHER,
                'teacher_id' => $teacher->id,
            ]);
            $this->line('Создан User #'.$user->id.' (пароль случайный — для доступа в панель выдайте через «Доступ» в TeacherResource).');
        } else {
            if ($user->teacher_id === null) {
                $user->teacher_id = $teacher->id;
            }
            if ((string) $user->role !== Roles::TEACHER && ! in_array($user->role, [Roles::SUPER_ADMIN, Roles::ADMIN, Roles::MANAGER], true)) {
                $user->role = Roles::TEACHER;
            }
            $user->save();
            $this->line('Найден User #'.$user->id.' (role='.$user->role.').');
        }

        $user->forceFill(['telegram_id' => $telegramId])->save();

        SocialAccount::firstOrCreate(
            ['provider' => SocialAccount::PROVIDER_TELEGRAM, 'provider_id' => (string) $telegramId],
            ['user_id' => $user->id, 'email' => $user->email],
        );

        $this->info('Готово: Teacher #'.$teacher->id.' («'.$teacher->name.'») ↔ User #'.$user->id.' ↔ telegram '.$telegramId.'.');

        return self::SUCCESS;
    }
}
