<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Group;
use App\Models\User;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Filament\Notifications\Notification;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importUsers')
                ->label('Импорт CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('info')
                ->form([
                    Select::make('group_id')
                        ->label('В какую группу добавить?')
                        ->options(Group::pluck('name', 'id'))
                        ->required()
                        ->searchable(),
                        
                    FileUpload::make('csv_file')
                        ->label('Файл CSV')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->disk('public') // Явно указываем диск для загрузки
                        ->directory('imports')
                        ->required(),
                ])
                ->action(function (array $data) {
                    // 1. Получаем путь к файлу (с учетом того, что это может быть массив)
                    $fileData = $data['csv_file'];
                    
                    // Если Filament вернул массив путей, берем первый
                    $pathString = is_array($fileData) ? reset($fileData) : $fileData;
                    
                    // ПОЛУЧАЕМ ПРАВИЛЬНЫЙ ПОЛНЫЙ ПУТЬ С ДИСКА PUBLIC
                    $filePath = Storage::disk('public')->path($pathString);
                    
                    // 2. Читаем файл
                    if (!file_exists($filePath)) {
                        Notification::make()
                            ->title('Ошибка')
                            ->body('Файл не найден. Попробуйте еще раз.')
                            ->danger()
                            ->send();
                        return;
                    }

                    $file = fopen($filePath, 'r');
                    $importedCount = 0;
                    
                    while (($row = fgetcsv($file, 1000, ",")) !== FALSE) {
                        // Ожидаем формат: [0] => Name, [1] => Email
                        $name = $row[0] ?? null;
                        $email = $row[1] ?? null;

                        // Простая валидация: имя есть, в email есть собачка
                        if ($name && $email && str_contains($email, '@')) {
                            // Чистим от лишних пробелов
                            $cleanEmail = trim($email);
                            $cleanName = trim($name);

                            // Создаем или находим
                            $user = User::firstOrCreate(
                                ['email' => $cleanEmail],
                                [
                                    'name' => $cleanName,
                                    'password' => Hash::make('sanskrit108'),
                                ]
                            );
                            
                            // Привязываем к группе
                            $user->groups()->syncWithoutDetaching([$data['group_id']]);
                            $importedCount++;
                        }
                    }
                    
                    fclose($file);
                    
                    // Удаляем файл после обработки
                    Storage::disk('public')->delete($pathString);

                    Notification::make()
                        ->title('Импорт завершен')
                        ->body("Обработано студентов: $importedCount")
                        ->success()
                        ->send();
                }),

            Actions\CreateAction::make(),
        ];
    }
}
