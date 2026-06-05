<?php

namespace App\Filament\Resources\TeacherResource\Pages;

use App\Filament\Resources\TeacherResource;
use App\Mail\TeacherInviteMail;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EditTeacher extends EditRecord
{
    protected static string $resource = TeacherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * После сохранения карточки: если в форме указан новый пароль — сбрасываем его
     * в связанном User-аккаунте. Если у преподавателя есть email, но юзера нет —
     * создаём его (миграционный сценарий для старых карточек без аккаунта).
     */
    protected function afterSave(): void
    {
        /** @var Teacher $teacher */
        $teacher = $this->record;

        $password = $this->form->getRawState()['account_password'] ?? null;

        $email = trim((string) ($teacher->email ?? ''));
        if ($email === '') {
            return;
        }

        /** @var User $user */
        $user = User::firstOrNew(['email' => $email]);
        $isNewUser = ! $user->exists;

        $user->name = $user->name ?: $teacher->name;
        $user->role = Roles::TEACHER;
        $user->teacher_id = $teacher->id;

        // Открытый пароль для письма — только когда реально задаём новый.
        $plainPassword = null;
        if ($isNewUser || filled($password)) {
            $plainPassword = filled($password) ? (string) $password : Str::random(12);
            $user->password = Hash::make($plainPassword);
        }

        $user->save();

        // Письмо-приглашение шлём только при значимых событиях: создан аккаунт
        // (миграция старой карточки) или админ задал/сбросил пароль. Иначе обычное
        // сохранение карточки (правка био и т.п.) не должно слать письмо каждый раз.
        if ($isNewUser || filled($password)) {
            Mail::to($user->email)->send(new TeacherInviteMail($user, $plainPassword));
        }

        if ($isNewUser) {
            Notification::make()
                ->title('Создан аккаунт преподавателя')
                ->body("Логин: {$email}. Письмо-приглашение отправлено. ".(filled($password)
                    ? 'Используется указанный вами пароль.'
                    : 'Сгенерирован случайный пароль и отправлен преподавателю в письме.'))
                ->success()
                ->send();
        } elseif (filled($password)) {
            Notification::make()
                ->title('Пароль преподавателя обновлён')
                ->body('Новый пароль отправлен преподавателю на почту.')
                ->success()
                ->send();
        }
    }
}
