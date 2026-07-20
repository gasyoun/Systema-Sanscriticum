<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CertificateResource\Pages;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use App\Services\CertificateService;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CertificateResource extends Resource
{
    protected static ?string $model = Certificate::class;

    // Доступ: админ — ко всем сертификатам; преподаватель — только к сертификатам
    // своих курсов (через Certificate->course->teacher_id). Идиом повторяет
    // LessonResource: scope в getEloquentQuery() + серверная валидация course_id.
    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public static function canCreate(): bool
    {
        if (RoleGate::any(Roles::ADMIN)) {
            return true;
        }
        // Преподаватель может выдавать сертификаты только при заполненном
        // teacher_id — иначе scope course_id = 1=0 и сохранение всё равно упадёт.
        $user = auth()->user();

        return $user?->isTeacher() === true && $user->teacher_id !== null;
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if ($user->isAdminLike()) {
            return true;
        }

        return $user->isTeacher()
            && optional($record->course)->isTaughtBy($user->teacher_id) === true;
    }

    public static function canDelete($record): bool
    {
        return self::canEdit($record);
    }

    public static function canDeleteAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();
        if ($user && $user->isTeacher()) {
            $query->whereHas('course', fn ($q) => $q->forTeacher($user->teacher_id));
        }

        return $query;
    }

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $navigationLabel = 'Сертификаты';

    protected static ?string $pluralModelLabel = 'Сертификаты';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // 1. Выбор Студента (обязательно)
                Forms\Components\Select::make('user_id')
                    ->label('Студент')
                    ->relationship('user', 'name') // Ищем по имени в таблице users
                    ->searchable()
                    ->preload()
                    ->required() // <--- ВАЖНО: Не даст сохранить без выбора
                    ->live()
                    // Подставляем ФИО из профиля в редактируемое поле сертификата.
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        $set('student_name', User::find($state)?->name);
                    }),

                // 2. Выбор Курса (обязательно)
                Forms\Components\Select::make('course_id')
                    ->label('Курс')
                    ->relationship(
                        name: 'course',
                        titleAttribute: 'title', // Ищем по названию в таблице courses
                        modifyQueryUsing: function (Builder $query) {
                            // Преподаватель видит в селекте свои курсы (основной ИЛИ
                            // со-препод); админ — все. Без teacher_id — пусто.
                            $user = auth()->user();
                            if ($user && $user->isTeacher()) {
                                $query->forTeacher($user->teacher_id);
                            }
                        },
                    )
                    // Серверная валидация: Filament-default Rule::exists для relationship-Select
                    // не накладывает where, поэтому преподаватель может через POST передать
                    // чужой course_id. Closure-правило учитывает и основного, и со-препода.
                    ->rule(fn () => Course::teacherCourseValidationRule())
                    ->searchable()
                    ->preload()
                    ->required() // <--- ВАЖНО
                    ->live()
                    // Подставляем название курса в редактируемое поле сертификата.
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        $set('course_title', Course::find($state)?->title);
                    }),

                // 3. ФИО, как оно будет напечатано в сертификате (по умолчанию из профиля).
                Forms\Components\TextInput::make('student_name')
                    ->label('ФИО в сертификате')
                    ->helperText('Подставлено из профиля студента. Уберите лишнее — город, пометки.'),

                // 4. Название курса, как оно будет напечатано в сертификате.
                Forms\Components\TextInput::make('course_title')
                    ->label('Название курса в сертификате')
                    ->helperText('Подставлено из курса. Символ | — перенос строки.'),

                // 5. Шаблон сертификата (роспись преподавателя).
                Forms\Components\Select::make('template')
                    ->label('Шаблон (роспись)')
                    ->options(Certificate::templateOptions())
                    ->default('gasuns')
                    ->required()
                    ->live(),

                // 5a. Баллы за экзамен — только для шаблона «Санка».
                Forms\Components\Fieldset::make('Баллы за экзамен')
                    ->visible(fn (Forms\Get $get) => $get('template') === 'sanka')
                    ->columns(3)
                    ->schema(
                        collect(Certificate::EXAM_CRITERIA)
                            ->map(fn (array $crit, string $field) => Forms\Components\TextInput::make($field)
                                ->label($crit['label'])
                                ->numeric()
                                ->minValue(0)
                                ->maxValue($crit['max'])
                                ->step(0.5)
                                ->suffix('/ '.$crit['max'])
                            )
                            ->values()
                            ->all()
                    ),

                // 6. Путь к файлу (если загружаем вручную, но мы теперь генерируем их)
                // Можно оставить необязательным или вообще скрыть, раз у нас автогенерация
                Forms\Components\TextInput::make('file_path')
                    ->label('Путь к файлу (необязательно при автогенерации)')
                    ->disabled() // Блокируем, чтобы руками не писали ерунду
                    ->placeholder('Генерируется автоматически'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Студент')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->sortable(),
                Tables\Columns\TextColumn::make('template')
                    ->label('Шаблон')
                    ->formatStateUsing(fn ($state) => Certificate::TEMPLATES[$state]['label'] ?? $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('number')
                    ->label('Номер сертификата')
                    ->searchable(),
                Tables\Columns\TextColumn::make('issued_at')
                    ->label('Дата выдачи')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('jpg')
                    ->label('JPG')
                    ->icon('heroicon-o-photo')
                    ->visible(fn () => CertificateService::jpegSupported())
                    ->action(fn (Certificate $record) => response()->streamDownload(
                        fn () => print app(CertificateService::class)->generateJpegBytes($record),
                        'Certificate_'.$record->number.'.jpg',
                        ['Content-Type' => 'image/jpeg'],
                    )),
                Tables\Actions\DeleteAction::make(), // Кнопка отзыва сертификата
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCertificates::route('/'),
            'create' => Pages\CreateCertificate::route('/create'),
            'edit' => Pages\EditCertificate::route('/{record}/edit'),
        ];
    }
}
