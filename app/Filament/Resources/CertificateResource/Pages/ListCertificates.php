<?php

namespace App\Filament\Resources\CertificateResource\Pages;

use App\Filament\Resources\CertificateResource;
use App\Jobs\GenerateCertificatesArchive;
use App\Models\Certificate;
use App\Models\CertificateMilestone;
use App\Models\Course;
use App\Models\Group;
use App\Services\CertificateIssuedNotifier;
use App\Services\MilestoneCertificateIssuer;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords; // Подключаем нашу Job

class ListCertificates extends ListRecords
{
    protected static string $resource = CertificateResource::class;

    /**
     * Курсы для выпадашек массовой выдачи. Преподаватель видит только свои
     * (по teacher_id); без teacher_id — пустой список. Админ — все курсы.
     * Зеркалит scope формы и getEloquentQuery() в CertificateResource.
     */
    protected static function courseOptions(): array
    {
        $user = auth()->user();
        $query = Course::query();
        if ($user && $user->isTeacher()) {
            // Курсы препода: основной ИЛИ со-препод. Без teacher_id — пусто.
            $query->forTeacher($user->teacher_id);
        }

        return $query->orderBy('title')->pluck('title', 'id')->all();
    }

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
                        ->options(static::courseOptions())
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

                    // Серверная защита: опции курса скрыты на фронте, но teacher
                    // мог прислать чужой course_id через POST. Пропускаем только
                    // свои курсы (зеркалит rule() в CertificateResource::form).
                    $user = auth()->user();
                    if ($user?->isTeacher()) {
                        $owns = Course::query()
                            ->forTeacher($user->teacher_id)
                            ->whereKey($courseId)
                            ->exists();
                        if (! $owns) {
                            Notification::make()->title('Этот курс не закреплён за вами')->danger()->send();

                            return;
                        }
                    }

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

            // 1a. КНОПКА: ВЫДАТЬ ПО ВЕХЕ СЕЙЧАС — ручной запуск автовыдачи, не
            // дожидаясь ежедневной certificates:issue-milestones. Право на
            // сертификат (оплата блоков, активный состав групп) проверяет
            // MilestoneCertificateIssuer — здесь только выбор вехи и отчёт.
            Actions\Action::make('issue_milestone')
                ->label('Выдать по вехе')
                ->icon('heroicon-o-flag')
                ->color('warning')
                ->form([
                    Select::make('course_id')
                        ->label('Курс')
                        ->options(static::courseOptions())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live(),

                    Select::make('milestone_id')
                        ->label('Веха')
                        ->options(fn (Get $get) => CertificateMilestone::query()
                            ->where('course_id', $get('course_id'))
                            ->orderBy('start_block')
                            ->pluck('title', 'id')
                            ->all())
                        ->required()
                        ->helperText('Получат оплатившие блоки вехи участники активных групп курса, у которых сертификата ещё нет. Уведомление уйдёт сразу.'),
                ])
                ->action(function (array $data) {
                    // Серверная защита от чужого course_id через POST (зеркалит issue_bulk).
                    $user = auth()->user();
                    if ($user?->isTeacher()) {
                        $owns = Course::query()
                            ->forTeacher($user->teacher_id)
                            ->whereKey($data['course_id'])
                            ->exists();
                        if (! $owns) {
                            Notification::make()->title('Этот курс не закреплён за вами')->danger()->send();

                            return;
                        }
                    }

                    $milestone = CertificateMilestone::query()
                        ->where('course_id', $data['course_id'])
                        ->find($data['milestone_id']);
                    if (! $milestone) {
                        Notification::make()->title('Веха не найдена')->danger()->send();

                        return;
                    }

                    if ($milestone->template === 'sanka') {
                        Notification::make()
                            ->title('Экзаменационный шаблон выдаётся только вручную')
                            ->body('«Санка» требует баллов экзамена — создайте сертификаты через форму.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $issued = app(MilestoneCertificateIssuer::class)
                        ->issueForMilestone($milestone, force: true);

                    $notifier = app(CertificateIssuedNotifier::class);
                    $issued->each(fn (Certificate $c) => $notifier->notify($c));

                    Notification::make()
                        ->title('Готово')
                        ->body("Выдано новых сертификатов: {$issued->count()}")
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
                    // Группа не привязана к курсу, поэтому преподавателю архив
                    // фильтруется по его курсам (course.teacher_id) внутри джобы,
                    // иначе в ZIP попали бы сертификаты чужих курсов тех же студентов.
                    $user = auth()->user();
                    $teacherId = $user?->isTeacher() ? ($user->teacher_id ?: 0) : null;

                    GenerateCertificatesArchive::dispatch(
                        $data['group_id'],
                        auth()->id(),
                        $teacherId
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
