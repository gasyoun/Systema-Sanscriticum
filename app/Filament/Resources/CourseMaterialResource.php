<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CourseMaterialResource\Pages;
use App\Models\CourseMaterial;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Библиотека курсов — фаза 1: реестр ссылок на литературу.
 *
 * Файлы не забираем: преподаватель кладёт PDF там, где ему удобно (Dropbox,
 * Google Drive), а мы храним строку с нормализованной ссылкой. Ноль расхода
 * хранилища — осознанный выбор, см. config/features.php.
 *
 * Права повторяют LessonResource: библиотеку курса ведёт тот же преподаватель,
 * который выкладывает ссылки, а не только админ.
 */
class CourseMaterialResource extends Resource
{
    protected static ?string $model = CourseMaterial::class;

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public static function canCreate(): bool
    {
        if (RoleGate::any(Roles::ADMIN)) {
            return true;
        }
        // Как в LessonResource: без teacher_id scope курсов даст 1=0 и
        // сохранение всё равно упадёт — лучше не показывать кнопку.
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

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?string $navigationLabel = 'Библиотека курсов';

    protected static ?string $pluralModelLabel = 'Библиотека курсов';

    protected static ?string $modelLabel = 'материал';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('course_id')
                ->label('Курс')
                ->relationship('course', 'title')
                ->modifyQueryUsing(function (Builder $query) {
                    $user = auth()->user();
                    if ($user && $user->isTeacher()) {
                        $query->forTeacher($user->teacher_id);
                    }
                })
                ->searchable()
                ->preload()
                ->required()
                // Смена курса обнуляет урок: список уроков зависит от курса.
                ->live()
                ->afterStateUpdated(fn (Forms\Set $set) => $set('lesson_id', null)),

            Forms\Components\TextInput::make('source_url')
                ->label('Ссылка на материал')
                ->helperText('Как дал преподаватель. Dropbox/Google Drive приведём к прямой ссылке сами; rlkey не трогаем.')
                ->url()
                ->maxLength(2048)
                ->required()
                ->columnSpanFull(),

            Forms\Components\TextInput::make('title')
                ->label('Название')
                ->helperText('Оставьте пустым — подставим черновик из имени файла.')
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\Grid::make(3)->schema([
                Forms\Components\TextInput::make('author')
                    ->label('Автор')
                    ->maxLength(255),

                Forms\Components\TextInput::make('year')
                    ->label('Год')
                    ->maxLength(32),

                Forms\Components\Select::make('kind')
                    ->label('Тип')
                    ->options(CourseMaterial::KINDS)
                    ->helperText('Пусто — определим по расширению.'),
            ]),

            Forms\Components\Select::make('lesson_id')
                ->label('Урок')
                ->helperText('Пусто — материал всего курса. Указан урок — материал наследует его замок.')
                ->relationship('lesson', 'title')
                ->modifyQueryUsing(function (Builder $query, Forms\Get $get) {
                    $courseId = $get('course_id');
                    $query->where('course_id', $courseId ?: 0)->orderBy('sort_order');
                })
                ->searchable()
                ->preload(),

            Forms\Components\Textarea::make('note')
                ->label('Примечание для студента')
                ->rows(2)
                ->columnSpanFull(),

            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Toggle::make('is_visible')
                    ->label('Показывать студентам')
                    ->helperText('По умолчанию выключено: сперва проверьте ссылку.'),

                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->default(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->wrap()
                    ->url(fn (CourseMaterial $record): string => $record->effectiveUrl())
                    ->openUrlInNewTab(),

                Tables\Columns\TextColumn::make('author')
                    ->label('Автор')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('year')
                    ->label('Год')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('kind')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CourseMaterial::KINDS[$state] ?? (string) $state),

                Tables\Columns\TextColumn::make('host')
                    ->label('Где лежит')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('lesson.title')
                    ->label('Урок')
                    ->placeholder('весь курс')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_visible')
                    ->label('Видно')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('course_id')
                    ->label('Курс')
                    ->relationship('course', 'title')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('kind')
                    ->label('Тип')
                    ->options(CourseMaterial::KINDS),

                Tables\Filters\TernaryFilter::make('is_visible')
                    ->label('Видно студентам'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Библиотека ссылок пуста')
            ->emptyStateDescription('Сюда кладут литературу ссылкой (Dropbox, Google Drive). Еженедельные файлы хинди и других курсов прикрепляются к уроку — таблица файлов на этой же странице выше, либо карточка урока.')
            ->emptyStateActions([
                Tables\Actions\Action::make('openLessons')
                    ->label('Открыть уроки')
                    ->url(fn (): string => LessonResource::getUrl('index')),
                Tables\Actions\CreateAction::make(),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourseMaterials::route('/'),
            'create' => Pages\CreateCourseMaterial::route('/create'),
            'edit' => Pages\EditCourseMaterial::route('/{record}/edit'),
        ];
    }
}
