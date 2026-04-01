<?php

namespace App\Filament\Imports;

use App\Models\User;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Facades\Hash;

class UserImporter extends Importer
{
    protected static ?string $model = User::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->requiredMapping()
                ->rules(['required', 'max:255'])
                ->label('Имя'),
                
            ImportColumn::make('email')
                ->requiredMapping()
                ->rules(['required', 'email', 'max:255'])
                ->label('Email'),
        ];
    }

    public function resolveRecord(): ?User
    {
        // Ищем студента по email. Если такого нет — создаем нового.
        $user = User::firstOrNew([
            'email' => $this->data['email'],
        ]);

        // Если это абсолютно новый ученик, задаем ему дефолтный пароль
        if (! $user->exists) {
            // Пароль по умолчанию для всех импортированных. 
            // Студенты потом смогут его сменить.
            $user->password = Hash::make('sanskrit108'); 
        }

        return $user;
    }

    // Этот хук срабатывает сразу ПОСЛЕ того, как студент сохранен в базу
    protected function afterSave(): void
    {
        // Достаем ID группы, которую ты выбрал во всплывающем окне перед импортом
        $groupId = $this->options['group_id'] ?? null;
        
        if ($groupId) {
            // Привязываем студента к группе (syncWithoutDetaching не удалит его старые группы, если они есть)
            $this->record->groups()->syncWithoutDetaching([$groupId]);
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Импорт студентов завершен. Успешно загружено: ' . number_format($import->successful_rows) . '.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' Ошибок: ' . number_format($failedRowsCount) . '.';
        }

        return $body;
    }
}
