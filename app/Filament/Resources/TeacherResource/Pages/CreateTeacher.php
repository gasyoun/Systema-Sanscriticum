<?php

namespace App\Filament\Resources\TeacherResource\Pages;

use App\Filament\Resources\TeacherResource;
use App\Mail\TeacherInviteMail;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CreateTeacher extends CreateRecord
{
    protected static string $resource = TeacherResource::class;

    /**
     * Создаём карточку преподавателя и сразу же — связанный User-аккаунт
     * с ролью «Преподаватель». Если пользователь с таким email уже есть —
     * привязываем к нему teacher_id и переводим в роль преподавателя.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $password = $this->form->getRawState()['account_password'] ?? null;

        return DB::transaction(function () use ($data, $password) {
            /** @var Teacher $teacher */
            $teacher = static::getModel()::create($data);

            $email = trim((string) ($teacher->email ?? ''));
            if ($email === '') {
                return $teacher;
            }

            /** @var User $user */
            $user = User::firstOrNew(['email' => $email]);
            $isNewUser = ! $user->exists;

            $user->name = $user->name ?: $teacher->name;
            $user->role = Roles::TEACHER;
            $user->teacher_id = $teacher->id;

            // Открытый пароль для письма-приглашения: задаём только когда реально
            // выставляем новый пароль (новый аккаунт или явный ввод админом).
            // Существующему пользователю пароль не трогаем — в письме предложим войти
            // текущим паролем или восстановить его.
            $plainPassword = null;
            if ($isNewUser || filled($password)) {
                $plainPassword = filled($password) ? (string) $password : Str::random(12);
                $user->password = Hash::make($plainPassword);
            }

            $user->save();

            // Письмо-приглашение со ссылкой на панель и доступами (фоновая очередь).
            Mail::to($user->email)->send(new TeacherInviteMail($user, $plainPassword));

            Notification::make()
                ->title($isNewUser ? 'Аккаунт преподавателя создан' : 'Существующий пользователь привязан к преподавателю')
                ->body("Логин: {$email} · Письмо-приглашение отправлено".($isNewUser ? ' · Роль: Преподаватель' : ' · Роль обновлена на «Преподаватель»'))
                ->success()
                ->send();

            return $teacher;
        });
    }
}
