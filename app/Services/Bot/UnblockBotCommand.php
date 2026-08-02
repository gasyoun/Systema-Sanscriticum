<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\AccessAttempt;
use App\Models\User;
use App\Services\Access\StudentUnblockService;
use App\Support\Roles;

/**
 * Telegram-команда `/unblock <email>` и обработка inline-кнопки «Разблокировать»
 * из проактивного алерта (H849) — куратор/админ спасает застрявшего студента
 * прямо из Telegram, не заходя в кабинет. Выдаёт одноразовую ссылку для входа.
 *
 * Права совпадают с RoleGate::canIssueStudentLoginLink() (admin + manager +
 * super_admin), но без auth() — bot-контекст может быть без web-сессии.
 */
class UnblockBotCommand
{
    public const COMMAND = '/unblock';

    public function __construct(private StudentUnblockService $unblocker) {}

    public function isCommand(string $text): bool
    {
        $trimmed = trim($text);

        return $trimmed === self::COMMAND || str_starts_with($trimmed, self::COMMAND.' ');
    }

    public static function isAuthorized(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isSuperAdmin()
            || $user->role === Roles::ADMIN
            || $user->role === Roles::MANAGER;
    }

    /** Ответ на текстовую команду `/unblock <email>`. */
    public function replyForCommand(User $admin, string $text): string
    {
        $email = mb_strtolower(trim(mb_substr(trim($text), mb_strlen(self::COMMAND))));

        if ($email === '') {
            return 'Использование: /unblock адрес@почты';
        }

        $student = User::where('email', $email)->first();
        if ($student === null) {
            return "Студент с email {$email} не найден.";
        }

        return $this->unblockAndFormat($admin, $student);
    }

    /** Разблокировка по id записи из ленты — для нажатия inline-кнопки алерта. */
    public function replyForAttempt(User $admin, int $attemptId): string
    {
        $attempt = AccessAttempt::find($attemptId);
        if ($attempt === null || $attempt->user === null) {
            return 'Запись не найдена или к ней не привязан студент.';
        }

        return $this->unblockAndFormat($admin, $attempt->user);
    }

    private function unblockAndFormat(User $admin, User $student): string
    {
        $result = $this->unblocker->unblock($student, $admin->id, false);
        $name = $student->name ?: $student->email;

        return "🔓 Разблокирован: {$name}\n"
            ."Одноразовая ссылка для входа (24 ч) — передайте студенту:\n"
            .$result['login_link'];
    }
}
