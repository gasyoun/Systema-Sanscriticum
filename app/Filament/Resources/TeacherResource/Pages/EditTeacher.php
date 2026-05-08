<?php

namespace App\Filament\Resources\TeacherResource\Pages;

use App\Filament\Resources\TeacherResource;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;

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
        $isNewUser = !$user->exists;

        $user->name       = $user->name ?: $teacher->name;
        $user->role       = Roles::TEACHER;
        $user->teacher_id = $teacher->id;

        if ($isNewUser || filled($password)) {
            $user->password = Hash::make($password ?: \Illuminate\Support\Str::random(12));
        }

        $user->save();

        if ($isNewUser) {
            Notification::make()
                ->title('Создан аккаунт преподавателя')
                ->body("Логин: {$email}. " . (filled($password)
                    ? 'Используется указанный вами пароль.'
                    : 'Сгенерирован случайный пароль — задайте новый через эту же форму.'))
                ->success()
                ->send();
        } elseif (filled($password)) {
            Notification::make()
                ->title('Пароль преподавателя обновлён')
                ->success()
                ->send();
        }
    }
}
