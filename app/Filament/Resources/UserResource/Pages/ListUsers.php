<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Group;
use App\Models\User;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
// Добавляем специальный класс для кнопок внутри форм:
use Filament\Forms\Components\Actions\Action as FormAction; 
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
                        ->placeholder('Не добавлять в группу (просто загрузить)') // <-- Добавили подсказку
                        ->searchable(), // <-- Убрали ->required()
                        
                    FileUpload::make('csv_file')
                        ->label('Файл CSV')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->disk('public')
                        ->directory('imports')
                        ->required()
                        // --- КНОПКА СКАЧИВАНИЯ ШАБЛОНА ---
                        ->hintAction(
                            FormAction::make('downloadTemplate')
                                ->label('Скачать шаблон')
                                ->icon('heroicon-m-arrow-down-tray')
                                ->action(function () {
                                    $csvContent = "Name,Email\nИван Иванов,ivan@example.com\nАнна Смирнова,anna@example.com";
                                    return response()->streamDownload(function () use ($csvContent) {
                                        echo $csvContent;
                                    }, 'students_template.csv');
                                })
                        ),
                ])
                ->action(function (array $data) {
                    // 1. Получаем путь к файлу
                    $fileData = $data['csv_file'];
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
                    $firstRow = true;

                    while (($row = fgetcsv($file, 1000, ",")) !== FALSE) {
                        if ($firstRow) {
                            $firstRow = false;
                            if (strtolower(trim($row[0])) === 'name' || strtolower(trim($row[0])) === 'имя') {
                                continue; 
                            }
                        }

                        $name = $row[0] ?? null;
                        $email = $row[1] ?? null;

                        if ($name && $email && str_contains($email, '@')) {
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
                            
                            // --- ИЗМЕНЕНИЕ ЗДЕСЬ: Проверяем, выбрана ли группа ---
                            if (!empty($data['group_id'])) {
                                $user->groups()->syncWithoutDetaching([$data['group_id']]);
                            }
                            
                            $importedCount++;
                        }
                    }
                    
                    fclose($file);
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