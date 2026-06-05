<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\TeacherInviteMail;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Провижининг учётной записи преподавателя: создаёт/привязывает User по email
 * карточки, ставит роль «Преподаватель» и teacher_id, при необходимости задаёт
 * пароль и отправляет письмо-приглашение со ссылкой на панель.
 *
 * Используется кнопкой «Доступ» в таблице преподавателей (массовая выдача
 * доступов старым карточкам, заведённым до появления авто-аккаунтов).
 */
class TeacherAccountService
{
    /**
     * Сгенерировать новый пароль, создать/обновить аккаунт и отправить приглашение.
     *
     * Подходит для старых карточек: работает и когда User ещё нет, и когда он есть,
     * но пароль неизвестен (был случайный). Возвращает null, если у карточки нет email.
     */
    public function resetPasswordAndInvite(Teacher $teacher): ?User
    {
        $password = Str::random(12);

        return $this->provision($teacher, $password);
    }

    /**
     * Базовый провижининг. $password === null — пароль не трогаем (для уже
     * существующего аккаунта), в письме предложим войти текущим/восстановить.
     */
    public function provision(Teacher $teacher, ?string $password): ?User
    {
        $email = trim((string) ($teacher->email ?? ''));
        if ($email === '') {
            return null;
        }

        /** @var User $user */
        $user = User::firstOrNew(['email' => $email]);
        $isNewUser = ! $user->exists;

        $user->name = $user->name ?: $teacher->name;
        $user->role = Roles::TEACHER;
        $user->teacher_id = $teacher->id;

        $plainPassword = null;
        if ($isNewUser || filled($password)) {
            $plainPassword = filled($password) ? $password : Str::random(12);
            $user->password = Hash::make($plainPassword);
        }

        $user->save();

        Mail::to($user->email)->send(new TeacherInviteMail($user, $plainPassword));

        return $user;
    }
}
