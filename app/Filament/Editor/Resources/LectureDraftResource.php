<?php

declare(strict_types=1);

namespace App\Filament\Editor\Resources;

use App\Filament\Editor\Resources\LectureDraftResource\Pages;
use App\Models\Course;
use App\Models\LectureDraft;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LectureDraftResource extends Resource
{
    protected static ?string $model = LectureDraft::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Лекции';
    protected static ?string $pluralModelLabel = 'Черновики лекций';
    protected static ?string $modelLabel = 'Черновик лекции';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Метаданные лекции')
                    ->description('Эти поля попадут в meta-блок собранной страницы')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Название лекции')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\Select::make('course_id')
                            ->label('Курс (опционально)')
                            ->relationship('course', 'title')
                            ->searchable()
                            ->preload()
                            ->helperText('Можно оставить пустым; привязка к Lesson — на этапе публикации'),

                        Forms\Components\TextInput::make('meta.lesson_number')
                            ->label('Номер занятия')
                            ->numeric()
                            ->default(1)
                            ->minValue(0)
                            ->maxValue(999),

                        Forms\Components\TextInput::make('meta.lecturer')
                            ->label('Лектор')
                            ->placeholder('Иван Иванов'),

                        Forms\Components\TextInput::make('meta.host')
                            ->label('Ведущий')
                            ->placeholder('Марцис Гасунс'),

                        Forms\Components\TextInput::make('meta.video.youtube')
                            ->label('YouTube URL')
                            ->url()
                            ->placeholder('https://youtu.be/...'),

                        Forms\Components\TextInput::make('meta.video.rutube')
                            ->label('Rutube URL')
                            ->url()
                            ->placeholder('https://rutube.ru/video/...'),

                        Forms\Components\TextInput::make('meta.date_display')
                            ->label('Дата (отображение)')
                            ->placeholder('18 марта 2026'),

                        Forms\Components\TextInput::make('meta.period')
                            ->label('Период курса')
                            ->placeholder('Февраль 2026 – июнь 2026'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->wrap()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        LectureDraft::STATUS_DRAFT         => 'gray',
                        LectureDraft::STATUS_PREPROCESSING => 'warning',
                        LectureDraft::STATUS_EDITING       => 'info',
                        LectureDraft::STATUS_BUILT         => 'success',
                        LectureDraft::STATUS_PUBLISHED     => 'primary',
                        default                            => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        LectureDraft::STATUS_DRAFT         => 'Черновик',
                        LectureDraft::STATUS_PREPROCESSING => 'Препроцесс',
                        LectureDraft::STATUS_EDITING       => 'Редактирование',
                        LectureDraft::STATUS_BUILT         => 'Собрано',
                        LectureDraft::STATUS_PUBLISHED     => 'Опубликовано',
                        default                            => $state,
                    }),

                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('lesson.title')
                    ->label('Урок')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('author.name')
                    ->label('Автор')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        LectureDraft::STATUS_DRAFT         => 'Черновик',
                        LectureDraft::STATUS_PREPROCESSING => 'Препроцесс',
                        LectureDraft::STATUS_EDITING       => 'Редактирование',
                        LectureDraft::STATUS_BUILT         => 'Собрано',
                        LectureDraft::STATUS_PUBLISHED     => 'Опубликовано',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Открыть')
                    ->icon('heroicon-o-pencil-square'),

                Tables\Actions\DeleteAction::make()
                    ->after(function (LectureDraft $record) {
                        app(\App\Services\Lecture\LectureStorage::class)->deleteWorkingDir($record);
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        // Редактор видит только свои черновики; админ — всё
        $user = auth()->user();
        $query = parent::getEloquentQuery();

        if ($user && !$user->is_admin) {
            $query->where('created_by', $user->id);
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListLectureDrafts::route('/'),
            'create' => Pages\CreateLectureDraft::route('/create'),
            'edit'   => Pages\EditLectureDraft::route('/{record}/edit'),
        ];
    }
}
