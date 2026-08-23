<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Exports\ConnectedBotUsersExporter;
use App\Filament\Resources\UserResource;
use App\Models\Group;
use App\Models\User;
use Filament\Actions;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
// Добавляем специальный класс для кнопок внутри форм:
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

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
                        // H3310: приватный диск. В CSV — имена, email и
                        // дефолтные пароли; на disk('public') файл в окне
                        // импорта был доступен всем по /storage/imports/...
                        ->disk('local')
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
                ->action(fn (array $data) => $this->importFromCsv($data)),

            Actions\ExportAction::make('exportConnectedBot')
                ->label('Экспорт подключивших бота')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->exporter(ConnectedBotUsersExporter::class)
                ->formats([ExportFormat::Csv])
                ->fileName(fn () => 'bot-connected-'.now()->format('Y-m-d_H-i-s'))
                // Только студенты, реально подключившие TG или VK.
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('is_admin', false)
                    ->where(fn (Builder $q) => $q->whereNotNull('telegram_id')->orWhereNotNull('vk_id'))),

            Actions\CreateAction::make(),
        ];
    }

    /**
     * Читает CSV с приватного диска (H3310) и создаёт пользователей.
     * Семантика прежняя, диск сменился public → local вместе с полем формы.
     */
    public function importFromCsv(array $data): int
    {
        // Путь к файлу: FileUpload без storeFiles(false) кладёт строку пути,
        // но на всякий случай разворачиваем и массив — так было и раньше.
        $fileData = $data['csv_file'];
        $pathString = is_array($fileData) ? reset($fileData) : $fileData;

        $filePath = Storage::disk('local')->path((string) $pathString);

        if (! file_exists($filePath)) {
            Notification::make()
                ->title('Ошибка')
                ->body('Файл не найден. Попробуйте еще раз.')
                ->danger()
                ->send();

            return 0;
        }

        $file = fopen($filePath, 'r');
        $importedCount = 0;
        $firstRow = true;

        while (($row = fgetcsv($file, 1000, ',')) !== false) {
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

                if (! empty($data['group_id'])) {
                    $user->groups()->syncWithoutDetaching([$data['group_id']]);
                }

                $importedCount++;
            }
        }

        fclose($file);
        Storage::disk('local')->delete((string) $pathString);

        Notification::make()
            ->title('Импорт завершен')
            ->body("Обработано студентов: $importedCount")
            ->success()
            ->send();

        return $importedCount;
    }
}
