<?php

namespace App\Filament\Resources;

use App\Models\Lesson;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LessonResource extends Resource
{
    protected static ?string $model = Lesson::class;
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';
    protected static ?string $navigationLabel = 'Уроки';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('group_id')
                ->relationship('group', 'name')
                ->label('Группа')
                ->required(),
            Forms\Components\Select::make('course_id')
    ->relationship('course', 'title') // Показывает названия курсов
    ->searchable()
    ->preload()
    ->label('Курс')
    ->createOptionForm([ // Можно создать курс прямо из урока!
        Forms\Components\TextInput::make('title')
            ->required()
            ->label('Название курса'),
        Forms\Components\TextInput::make('slug')
            ->required(),
    ]),
            Forms\Components\TextInput::make('title')->label('Заголовок')->required(),
            Forms\Components\DatePicker::make('lesson_date')->label('Дата')->required(),
            Forms\Components\TextInput::make('video_url')->label('YouTube URL'),
            Forms\Components\TextInput::make('rutube_url')->label('Rutube URL'),
            Forms\Components\Textarea::make('topic')->label('Тема')->columnSpanFull(),
            Forms\Components\KeyValue::make('flash_cards')->label('Карточки')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('lesson_date')->date()->label('Дата'),
                Tables\Columns\TextColumn::make('group.name')->label('Группа'),
                Tables\Columns\TextColumn::make('title')->label('Урок'),
            ])
            ->filters([])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array { return ['index' => LessonResource\Pages\ListLessons::route('/'), 'create' => LessonResource\Pages\CreateLesson::route('/create'), 'edit' => LessonResource\Pages\EditLesson::route('/{record}/edit')]; }
}
