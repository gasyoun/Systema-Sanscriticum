<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LessonResource\Pages;
use App\Models\Course;
use App\Models\Lesson;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LessonResource extends Resource
{
    protected static ?string $model = Lesson::class;

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public static function canCreate(): bool
    {
        if (RoleGate::any(Roles::ADMIN)) {
            return true;
        }
        // Учитель может создавать уроки только если у него заполнен teacher_id —
        // иначе scope course_id будет 1=0 и сохранение всё равно упадёт.
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
            && $user->teacher_id
            && optional($record->course)->teacher_id === $user->teacher_id;
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
            if (! $user->teacher_id) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('course', function ($q) use ($user) {
                    $q->where('teacher_id', $user->teacher_id);
                });
            }
        }

        return $query;
    }

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
                    ->relationship(
                        name: 'course',
                        titleAttribute: 'title',
                        modifyQueryUsing: function (Builder $query) {
                            // Учитель видит в селекте только свои курсы; админ — все.
                            // Учителю без teacher_id вообще ничего не показываем.
                            $user = auth()->user();
                            if ($user && $user->isTeacher()) {
                                if (! $user->teacher_id) {
                                    $query->whereRaw('1 = 0');
                                } else {
                                    $query->where('teacher_id', $user->teacher_id);
                                }
                            }
                        },
                    )
                    // Серверная валидация: Filament-default Rule::exists для relationship-Select
                    // не накладывает where, поэтому учитель может через POST передать чужой
                    // course_id и BelongsTo::associate его сохранит. Дополняем явным правилом.
                    ->rule(function () {
                        $user = auth()->user();
                        if ($user?->isTeacher() && $user->teacher_id) {
                            return \Illuminate\Validation\Rule::exists('courses', 'id')
                                ->where('teacher_id', $user->teacher_id);
                        }
                        if ($user?->isTeacher() && ! $user->teacher_id) {
                            // Учитель без teacher_id — никакой курс не валиден.
                            return \Illuminate\Validation\Rule::in([]);
                        }

                        return null;
                    })
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
                    ->live()
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('show_on_main')
                    ->label('Показывать на главной странице')
                    ->helperText('Если включено — карточка урока появится в карусели «Бесплатные беседы вокруг санскрита» на витрине сайта. Требует включённого «Открытый урок».')
                    ->onColor('success')
                    ->disabled(fn (Forms\Get $get) => ! $get('is_free'))
                    ->dehydrated()
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('duration_seconds')
                    ->label('Длительность видео')
                    ->placeholder('1:23:45  /  12:34  /  754')
                    ->helperText('Опционально. Бейдж на карточке в карусели на главной. Поддерживается hh:mm:ss, mm:ss или просто секунды.')
                    ->dehydrateStateUsing(function (?string $state): ?int {
                        if ($state === null || trim($state) === '') {
                            return null;
                        }
                        $s = trim($state);
                        if (ctype_digit($s)) {
                            $n = (int) $s;

                            return $n > 0 ? $n : null;
                        }
                        $parts = array_reverse(array_map('intval', explode(':', $s)));
                        $seconds = ($parts[0] ?? 0) + ($parts[1] ?? 0) * 60 + ($parts[2] ?? 0) * 3600;

                        return $seconds > 0 ? $seconds : null;
                    })
                    ->formatStateUsing(function ($state): string {
                        if ($state === null || $state === '') {
                            return '';
                        }
                        $s = (int) $state;
                        $h = intdiv($s, 3600);
                        $m = intdiv($s % 3600, 60);
                        $sec = $s % 60;

                        return $h > 0
                            ? sprintf('%d:%02d:%02d', $h, $m, $sec)
                            : sprintf('%d:%02d', $m, $sec);
                    })
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
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // Word (docx)
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

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable()
                    ->toggleable(),

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

                Tables\Columns\IconColumn::make('show_on_main')
                    ->label('На главной')
                    ->boolean()
                    ->trueIcon('heroicon-o-star')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('course_id')
                    ->label('Курс')
                    ->relationship(
                        name: 'course',
                        titleAttribute: 'title',
                        modifyQueryUsing: function (Builder $query) {
                            // Зеркалим scope из form()->course_id и getEloquentQuery():
                            // учитель видит в выпадашке фильтра только свои курсы,
                            // потому что Filament не применяет getEloquentQuery() к опциям фильтра.
                            $user = auth()->user();
                            if ($user && $user->isTeacher()) {
                                if (! $user->teacher_id) {
                                    $query->whereRaw('1 = 0');
                                } else {
                                    $query->where('teacher_id', $user->teacher_id);
                                }
                            }
                        },
                    )
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('block_number')
                    ->label('Блок')
                    ->options(function () {
                        return static::getEloquentQuery()
                            ->select('block_number')
                            ->distinct()
                            ->orderBy('block_number')
                            ->pluck('block_number')
                            ->mapWithKeys(fn ($n) => [$n => "Блок {$n}"])
                            ->all();
                    })
                    ->searchable(),

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
            // Перетаскивание доступно только когда в фильтре выбран конкретный курс —
            // иначе Filament перенумеровал бы sort_order сразу по всем курсам и порядок
            // размешался бы между ними.
            ->reorderable(
                'sort_order',
                condition: fn ($livewire) => filled(
                    data_get($livewire->getTableFilterState('course_id'), 'value')
                ),
            )
            ->defaultSort(fn (Builder $query) => $query->orderBy('course_id')->orderBy('sort_order'));
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
