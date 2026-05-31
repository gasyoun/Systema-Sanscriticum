<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\CertificateResource\Pages;
use App\Models\Certificate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CertificateResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Certificate::class;

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
                        $set('student_name', \App\Models\User::find($state)?->name);
                    }),

                // 2. Выбор Курса (обязательно)
                Forms\Components\Select::make('course_id')
                    ->label('Курс')
                    ->relationship('course', 'title') // Ищем по названию в таблице courses
                    ->searchable()
                    ->preload()
                    ->required() // <--- ВАЖНО
                    ->live()
                    // Подставляем название курса в редактируемое поле сертификата.
                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                        $set('course_title', \App\Models\Course::find($state)?->title);
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
                    ->options(\App\Models\Certificate::templateOptions())
                    ->default('gasuns')
                    ->required()
                    ->live(),

                // 5a. Баллы за экзамен — только для шаблона «Санка».
                Forms\Components\Fieldset::make('Баллы за экзамен')
                    ->visible(fn (Forms\Get $get) => $get('template') === 'sanka')
                    ->columns(3)
                    ->schema(
                        collect(\App\Models\Certificate::EXAM_CRITERIA)
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
