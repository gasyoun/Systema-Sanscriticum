<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LessonResource\Pages;
use App\Models\Lesson;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\FileUpload;

class LessonResource extends Resource
{
    protected static ?string $model = Lesson::class;

    protected static ?string $navigationIcon = 'heroicon-o-play-circle';
    protected static ?int $navigationSort = 20;
    protected static ?string $navigationGroup = 'Обучение';
    protected static ?string $navigationLabel = 'Уроки';
    protected static ?string $pluralModelLabel = 'Уроки';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('course_id')
                    ->relationship('course', 'title') 
                    ->required()
                    ->label('Привязать к курсу'),

                Forms\Components\TextInput::make('title')
                    ->required()
                    ->label('Название урока'),
                    
                Forms\Components\Select::make('block_number')
    ->label('Блок (Модуль) курса')
    ->options(function () {
        $options = [];
        for ($i = 1; $i <= 100; $i++) {
            $startLesson = ($i - 1) * 4 + 1; // Высчитываем первое занятие в блоке
            $endLesson = $i * 4;             // Высчитываем последнее занятие в блоке
            $options[$i] = "Блок {$i} (Занятия {$startLesson}-{$endLesson})";
        }
        return $options;
    })
    ->default(1)
    ->required()
    ->searchable() // Добавил поиск, чтобы куратору было удобно искать 52-й блок, а не крутить список
    ->helperText('Студенты увидят этот урок, только если оплатят этот блок (или весь курс целиком).'),
                    
                Forms\Components\DateTimePicker::make('lesson_date')
                    ->label('Дата и время урока')
                    ->required()
                    ->default(now()),

                Forms\Components\Toggle::make('is_free')
                    ->label('Открытый урок / вебинар')
                    ->helperText('Доступен любому залогиненному студенту без покупки курса. Уроки появятся в кабинете в разделе «Открытые уроки».')
                    ->onColor('success')
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('topic')
                    ->label('Описание / Тема')
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('youtube_url')
                    ->label('Ссылка на YouTube')
                    ->placeholder('https://www.youtube.com/watch?v=...')
                    ->url() 
                    ->suffixIcon('heroicon-m-video-camera') 
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('rutube_url')
                    ->label('Ссылка на Rutube')
                    ->placeholder('https://rutube.ru/video/...')
                    ->url()
                    ->columnSpanFull(),

                // --- ПОЛЕ: ТРАНСКРИПЦИЯ (JSON) ---
                Forms\Components\FileUpload::make('transcript_file')
                    ->label('Транскрипция (JSON файл)')
                    ->acceptedFileTypes(['application/json'])
                    ->directory('transcripts') // Файлы будут лежать в storage/app/public/transcripts
                    ->maxSize(20480) // 20 MB, чтобы точно влезли большие лекции
                    ->preserveFilenames()
                    ->columnSpanFull()
                    ->helperText('Загрузите JSON-файл расшифровки лекции (например, из Nova-3), чтобы студенты могли читать текст и перематывать видео по клику.'),
                
                // --- ОБНОВЛЕННОЕ ПОЛЕ: МАТЕРИАЛЫ К УРОКУ ---
                Forms\Components\FileUpload::make('attachments')
                    ->label('Материалы к уроку (PDF, Аудио, Видео)')
                    ->multiple()
                    ->directory('lesson-materials')
                    ->preserveFilenames()
                    ->reorderable() // Позволяет менять порядок файлов перетаскиванием
                    ->appendFiles() // Позволяет добавлять новые файлы к уже загруженным
                    ->downloadable() // Можно скачать из админки
                    ->openable() // Можно открыть из админки
                    ->acceptedFileTypes([
                        'application/pdf', 
                        'audio/*',           // Любое аудио (mp3, wav, m4a, ogg)
                        'video/mp4',         // Видео MP4
                        'video/quicktime',   // Видео MOV (iPhone)
                        'video/webm',        // Видео WebM
                        'application/zip',   // Архивы
                        'application/msword', // Word (doc)
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' // Word (docx)
                    ])
                    ->maxSize(102400) // Максимальный вес ОДНОГО файла - 100 МБ (102400 КБ)
                    ->columnSpanFull()
                    ->helperText('Загружайте PDF, аудиолекции (MP3) или дополнительные видео. Максимум 100 МБ на файл.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->sortable(),

                Tables\Columns\TextColumn::make('block_number')
                    ->label('Блок')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Урок')
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_free')
                    ->label('Открытый')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-open')
                    ->falseIcon('heroicon-o-lock-closed')
                    ->trueColor('success')
                    ->falseColor('gray'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_free')
                    ->label('Открытость')
                    ->trueLabel('Только открытые')
                    ->falseLabel('Только платные'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    // Добавляем кнопку массового экспорта:
                    \Filament\Tables\Actions\ExportBulkAction::make()
                        ->exporter(\App\Filament\Exports\LessonExporter::class)
                        ->label('Экспорт для файлов'),
                ]),
            ])
            ->defaultSort('lesson_date', 'asc'); 
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLessons::route('/'),
            'create' => Pages\CreateLesson::route('/create'),
            'edit' => Pages\EditLesson::route('/{record}/edit'),
        ];
    }
}