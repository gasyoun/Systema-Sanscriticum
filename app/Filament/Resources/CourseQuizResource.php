<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CourseQuizResource\Pages;
use App\Models\Course;
use App\Models\CourseQuiz;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Квизы этапов курса (мини-курсы): банк вопросов с вариантами после каждого
 * этапа. Права повторяют CourseMaterialResource: админ и преподаватель курса.
 */
class CourseQuizResource extends Resource
{
    protected static ?string $model = CourseQuiz::class;

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public static function canCreate(): bool
    {
        return RoleGate::any(Roles::ADMIN);
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
        return RoleGate::any(Roles::ADMIN);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount('questions');

        $user = auth()->user();
        if ($user && $user->isTeacher()) {
            $query->whereHas('course', fn ($q) => $q->forTeacher($user->teacher_id));
        }

        return $query;
    }

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 26;

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?string $navigationLabel = 'Квизы этапов';

    protected static ?string $pluralModelLabel = 'Квизы этапов';

    protected static ?string $modelLabel = 'квиз';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('course_id')
                ->label('Курс')
                ->relationship('course', 'title')
                ->searchable()
                ->preload()
                ->required()
                // Смена курса сбрасывает этап: список блоков зависит от курса.
                ->live()
                ->afterStateUpdated(fn (Forms\Set $set) => $set('block_number', null)),

            Forms\Components\Select::make('block_number')
                ->label('Этап (блок курса)')
                ->options(fn (Forms\Get $get): array => CourseQuizResource::blockOptions($get('course_id')))
                ->helperText('Номер этапа из блоков курса. Один квиз на этап.')
                ->required(),

            Forms\Components\TextInput::make('title')
                ->label('Название')
                ->helperText('Например: «Квиз этапа 1 — Вводный урок».')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('description')
                ->label('Пояснение студенту')
                ->rows(2)
                ->columnSpanFull(),

            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('pass_score')
                    ->label('Порог зачёта, %')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(100)
                    ->default(60)
                    ->required(),

                Forms\Components\Toggle::make('is_active')
                    ->label('Показывать студентам')
                    ->default(true),
            ]),

            Forms\Components\Repeater::make('questions')
                ->label('Вопросы')
                ->relationship()
                ->schema([
                    Forms\Components\Textarea::make('question')
                        ->label('Вопрос')
                        ->required()
                        ->rows(2)
                        ->columnSpanFull(),

                    // По одному варианту на строку; на экране порядок
                    // перемешивается детерминированно для студента.
                    Forms\Components\Textarea::make('options')
                        ->label('Варианты (по одному на строку)')
                        ->required()
                        ->rows(4)
                        ->formatStateUsing(fn ($state): string => is_array($state) ? implode("\n", $state) : (string) $state)
                        ->dehydrateStateUsing(fn ($state): array => CourseQuizResource::parseLines((string) $state))
                        ->columnSpanFull(),

                    Forms\Components\Select::make('correct_option')
                        ->label('Правильный вариант')
                        ->required()
                        ->options(function (Forms\Get $get): array {
                            $lines = CourseQuizResource::parseLines((string) ($get('options') ?? ''));

                            return collect($lines)
                                ->mapWithKeys(fn (string $line, int $i): array => [$i => ($i + 1).'. '.mb_substr($line, 0, 80)])
                                ->all();
                        })
                        ->helperText('Номер строки с правильным ответом.'),

                    Forms\Components\Textarea::make('explanation')
                        ->label('Разбор ответа')
                        ->rows(2)
                        ->helperText('Показывается после проверки (верным и неверным ответам).'),

                    Forms\Components\Hidden::make('sort_order')
                        ->default(0),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->itemLabel(fn (array $state): string => filled($state['question'] ?? null) ? mb_substr((string) $state['question'], 0, 60) : 'Новый вопрос')
                ->collapsible()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('block_number')
                    ->label('Этап')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Название')
                    ->searchable(),
                Tables\Columns\TextColumn::make('questions_count')
                    ->label('Вопросов'),
                Tables\Columns\TextColumn::make('pass_score')
                    ->label('Порог, %'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('course')
                    ->relationship('course', 'title')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('course_id');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourseQuizzes::route('/'),
            'create' => Pages\CreateCourseQuiz::route('/create'),
            'edit' => Pages\EditCourseQuiz::route('/{record}/edit'),
        ];
    }

    /** Блоки курса для селекта этапа: number => title (или «Этап N» без блоков). */
    private static function blockOptions(?int $courseId): array
    {
        if (! $courseId) {
            return [];
        }

        $course = Course::find($courseId);

        return $course?->blocks
            ->mapWithKeys(fn ($block): array => [$block->number => 'Этап '.$block->number.($block->title ? ' — '.$block->title : '')])
            ->all() ?? [];
    }

    /**
     * Строки текстового поля вариантов: непустые, без нумерации редактора.
     *
     * @return list<string>
     */
    private static function parseLines(string $text): array
    {
        return collect(preg_split('/\r\n|\r|\n/', $text) ?: [])
            ->map(fn (string $line): string => trim(preg_replace('/^\s*[-*]\s+/', '', $line) ?? $line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();
    }
}
