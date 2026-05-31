<?php

namespace App\Filament\Resources\CertificateResource\Pages;

use App\Filament\Resources\CertificateResource;
use App\Jobs\GenerateCertificatesArchive;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Group;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords; // Подключаем нашу Job

class ListCertificates extends ListRecords
{
    protected static string $resource = CertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 1. КНОПКА: ВЫДАТЬ ГРУППЕ (Оставляем как было)
            Actions\Action::make('issue_bulk')
                ->label('Выдать группе')
                ->icon('heroicon-o-academic-cap')
                ->color('primary')
                ->form([
                    Select::make('course_id')
                        ->label('Выберите курс')
                        ->options(Course::pluck('title', 'id'))
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        // Подставляем название курса в редактируемое поле сертификата.
                        ->afterStateUpdated(function ($state, Set $set) {
                            $set('course_title', Course::find($state)?->title);
                        }),

                    TextInput::make('course_title')
                        ->label('Название курса в сертификате')
                        ->helperText('Применится ко всем сертификатам группы. Символ | — перенос строки. Пусто — берётся из курса.'),

                    Select::make('template')
                        ->label('Шаблон (роспись)')
                        ->options(Certificate::templateOptions())
                        ->default('gasuns')
                        ->required(),

                    Select::make('group_id')
                        ->label('Выберите группу студентов')
                        ->options(Group::pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->preload()
                        ->helperText('Сертификаты получат все студенты этой группы, у которых их еще нет.'),
                ])
                ->action(function (array $data) {
                    $groupId = $data['group_id'];
                    $courseId = $data['course_id'];
                    $group = Group::with('users')->find($groupId);

                    if (! $group || $group->users->isEmpty()) {
                        Notification::make()->title('В этой группе нет студентов')->warning()->send();

                        return;
                    }

                    $courseTitle = trim($data['course_title'] ?? '') ?: null;
                    $template = $data['template'] ?? 'gasuns';

                    $count = 0;
                    foreach ($group->users as $user) {
                        // Снимок ФИО/названия/шаблона проставляется только при создании
                        // нового сертификата; существующие не трогаем.
                        $cert = Certificate::firstOrCreate(
                            [
                                'user_id' => $user->id,
                                'course_id' => $courseId,
                            ],
                            [
                                'student_name' => $user->name,
                                'course_title' => $courseTitle,
                                'template' => $template,
                            ]
                        );
                        if ($cert->wasRecentlyCreated) {
                            $count++;
                        }
                    }

                    Notification::make()
                        ->title('Успешно!')
                        ->body("Выдано новых сертификатов: $count")
                        ->success()
                        ->send();
                }),

            // 2. КНОПКА: СКАЧАТЬ АРХИВОМ (Через очереди)
            Actions\Action::make('download_zip')
                ->label('Скачать архивом (ZIP)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->form([
                    Select::make('group_id')
                        ->label('Выберите группу')
                        ->options(Group::pluck('name', 'id'))
                        ->required()
                        ->searchable(),
                ])
                ->action(function (array $data) {
                    // === ЗАПУСКАЕМ ЗАДАЧУ В ФОН ===
                    GenerateCertificatesArchive::dispatch(
                        $data['group_id'],
                        auth()->id()
                    );
                    // ==============================

                    Notification::make()
                        ->title('Генерация запущена')
                        ->body('Это займет время. Мы пришлем уведомление (колокольчик), когда архив будет готов.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
