<?php

namespace App\Filament\Resources\TeacherResource\Pages;

use App\Filament\Resources\TeacherResource;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
            $isNewUser = !$user->exists;

            $user->name       = $user->name ?: $teacher->name;
            $user->role       = Roles::TEACHER;
            $user->teacher_id = $teacher->id;

            if ($isNewUser || filled($password)) {
                $user->password = Hash::make($password ?: \Illuminate\Support\Str::random(12));
            }

            $user->save();

            Notification::make()
                ->title($isNewUser ? 'Аккаунт преподавателя создан' : 'Существующий пользователь привязан к преподавателю')
                ->body("Логин: {$email}" . ($isNewUser ? ' · Роль: Преподаватель' : ' · Роль обновлена на «Преподаватель»'))
                ->success()
                ->send();

            return $teacher;
        });
    }
}
