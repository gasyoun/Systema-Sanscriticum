<?php

namespace App\Filament\Resources;

use App\Filament\Exports\LessonExporter;
use App\Filament\Resources\LessonResource\Pages;
use App\Models\Course;
use App\Models\Lesson;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\TemporaryUploadedFile;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

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
                            // Учитель видит в селекте свои курсы (основной ИЛИ
                            // со-препод); админ — все. Без teacher_id — пусто.
                            $user = auth()->user();
                            if ($user && $user->isTeacher()) {
                                $query->forTeacher($user->teacher_id);
                            }
                        },
                    )
                    // Метка с ·#id + поиск: одноимённые курсы (когорты по годам) различимы
                    // при создании/редактировании урока.
                    ->getOptionLabelFromRecordUsing(fn (Course $record) => $record->title.' · #'.$record->id)
                    ->searchable()
                    // Серверная валидация: Filament-default Rule::exists для relationship-Select
                    // не накладывает where, поэтому учитель может через POST передать чужой
                    // course_id. Closure-правило учитывает и основного, и со-препода.
                    ->rule(fn () => Course::teacherCourseValidationRule())
                    ->required()
                    ->live()
                    ->label('Привязать к курсу'),

                // Группа курса — для курсов, разнесённых на 2 потока.
                // Пусто = урок виден всем группам курса (поведение по умолчанию).
                Forms\Components\Select::make('group_id')
                    ->label('Группа (для курсов из 2 потоков)')
                    ->helperText('Пусто — урок виден всем группам курса. Выберите группу, чтобы урок видела только она.')
                    ->options(function (Forms\Get $get): array {
                        $courseId = $get('course_id');
                        if (! $courseId) {
                            return [];
                        }

                        return Course::find($courseId)
                            ?->groups()->pluck('name', 'groups.id')->all() ?? [];
                    })
                    ->searchable()
                    ->nullable(),

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

                Forms\Components\Select::make('block_half')
                    ->label('Половина блока (если блок продаётся по частям)')
                    ->options([
                        1 => '1-я половина',
                        2 => '2-я половина',
                    ])
                    ->placeholder('Весь блок (не делится)')
                    ->helperText('Заполняйте только если этот блок продаётся половинами. Тогда КАЖДОМУ уроку блока проставьте 1 или 2 — иначе урок останется доступен лишь по полному блоку. Пусто = урок входит в целый блок.'),

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

                Forms\Components\Toggle::make('is_preview')
                    ->label('Пробный урок (публичный «Пример урока»)')
                    ->helperText('Бесплатный урок на продающей странице курса — открыт всем, включая гостей без регистрации. Не более одного на курс.')
                    ->onColor('info')
                    ->columnSpanFull()
                    // Валидация «не более одного preview на курс». Filament к голому
                    // Closure применяет evaluate() — оборачиваем во внешнее замыкание,
                    // как Course::teacherCourseValidationRule().
                    ->rule(fn (?Lesson $record, Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($record, $get) {
                        if (! $value) {
                            return;
                        }
                        $courseId = $get('course_id');
                        if (! $courseId) {
                            return;
                        }
                        $exists = Lesson::query()
                            ->where('course_id', $courseId)
                            ->where('is_preview', true)
                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                            ->exists();
                        if ($exists) {
                            $fail('У этого курса уже есть пробный урок — разрешён только один.');
                        }
                    }),

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

                Forms\Components\Select::make('recording_kind')
                    ->label('Класс записи')
                    ->options([
                        'course_lesson' => 'Урок курса (покупка курса)',
                        'club_stream' => 'Эфир клуба (членство Club/Top)',
                        'club_efir' => 'Эфир клуба (синоним)',
                    ])
                    ->default('course_lesson')
                    ->helperText('H3648: клуб открывает только эфиры/стримы. Обычная запись урока остаётся на покупке курса.')
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
                    // H3308: приватный диск — текст платной лекции не для /storage.
                    ->disk('local')
                    ->directory('transcripts')
                    ->maxSize(20480) // 20 MB, чтобы точно влезли большие лекции
                    ->preserveFilenames()
                    ->columnSpanFull()
                    ->helperText('Загрузите JSON-файл расшифровки лекции (например, из Nova-3), чтобы студенты могли читать текст и перематывать видео по клику.'),

                // --- ОБНОВЛЕННОЕ ПОЛЕ: МАТЕРИАЛЫ К УРОКУ ---
                Forms\Components\FileUpload::make('attachments')
                    ->label('Материалы к уроку (PDF, Аудио, Видео)')
                    ->multiple()
                    // H3308: приватный диск + сгенерированное читаемое имя вместо
                    // клиентского (путь/имя от клиента больше не попадают в /storage).
                    ->disk('local')
                    ->directory('lesson-materials')
                    ->getUploadedFileNameForStorageUsing(fn (BaseFileUpload $component, TemporaryUploadedFile $file) => self::safeStoredName($file))
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
                    // H1345: потолка по КОЛИЧЕСТВУ не было, а вместе с
                    // appendFiles() это значит, что один урок мог копить
                    // 100-мегабайтные видео без предела — крупнейший источник
                    // роста хранилища в проекте (и, поскольку storage/app
                    // целиком уходит в еженедельный бэкап, ещё и роста бэкапа).
                    ->maxFiles(20)
                    ->columnSpanFull()
                    ->helperText('Загружайте PDF, аудиолекции (MP3) или дополнительные видео. Максимум 100 МБ на файл, до 20 файлов на урок.'),

                // --- ДОМАШНЕЕ ЗАДАНИЕ ---
                Forms\Components\Section::make('Домашнее задание')
                    ->description('Если включено — студент сможет сдать работу на этом уроке, а преподаватель проверит её в разделе «Домашние работы».')
                    ->icon('heroicon-o-pencil-square')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Toggle::make('homework_enabled')
                            ->label('Включить домашнее задание на этом уроке')
                            ->live(),

                        Forms\Components\Textarea::make('homework_prompt')
                            ->label('Условие задания')
                            ->rows(4)
                            ->maxLength(10000)
                            ->columnSpanFull()
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('homework_enabled')),

                        Forms\Components\FileUpload::make('homework_attachments')
                            ->label('Справочные файлы к заданию (необязательно)')
                            ->multiple()
                            // H3308: приватный диск + сгенерированное имя — как у материалов.
                            ->disk('local')
                            ->directory('homework-prompts')
                            ->getUploadedFileNameForStorageUsing(fn (BaseFileUpload $component, TemporaryUploadedFile $file) => self::safeStoredName($file))
                            ->downloadable()
                            ->openable()
                            ->maxSize(51200)
                            ->maxFiles(10) // H1345: тот же незакрытый потолок по количеству
                            ->columnSpanFull()
                            ->helperText('До 10 файлов, каждый до 50 МБ.')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('homework_enabled')),

                        // --- АВТООТКРЫТИЕ (H1764) ---
                        // Единственное поле контура, которое заполняет человек.
                        // Без него урок в пилот не попадает: --dry-run покажет
                        // пустой список, и это сигнал, а не тишина.
                        Forms\Components\TextInput::make('textbook_lesson')
                            ->label('Занятие учебника Кочергиной')
                            ->helperText('Номер занятия, 1–40. Нужен для автооткрытия приёма ДЗ; пусто — урок в автооткрытии не участвует.')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(40),

                        // Разметка материала для material_count-вех сертификатов.
                        // Ручная правка фиксируется как manual — сканер стенограмм
                        // (LessonMaterialTagger) её не перезаписывает.
                        Forms\Components\TextInput::make('material_tag')
                            ->label('Тег материала (сертификаты)')
                            ->helperText('Латиницей: emeno, buhler. Обычно проставляется автоматически по стенограмме; заполните вручную, если автоматика ошиблась.')
                            ->maxLength(50)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('material_tag_source', 'manual')),
                        Forms\Components\Hidden::make('material_tag_source')
                            ->dehydrated(fn ($state) => $state !== null),

                        Forms\Components\Placeholder::make('homework_opens_at_display')
                            ->label('Приём откроется автоматически')
                            ->content(fn (?Lesson $record): string => $record?->homework_opens_at
                                ? $record->homework_opens_at->timezone('Europe/Moscow')->format('d.m.Y H:i').' МСК'
                                : 'Пока не рассчитано — момент появляется, когда у урока впервые загружена запись.')
                            ->helperText('Считается автоматически: запись + выдержка, с выравниванием на утро. Вручную не редактируется.'),
                    ])
                    ->columnSpanFull(),
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

                Tables\Columns\TextColumn::make('block_half')
                    ->label('Половина')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn ($state) => $state ? $state.'-я половина' : '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('group.name')
                    ->label('Группа')
                    ->badge()
                    ->color('success')
                    ->placeholder('Все группы')
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
                // Фильтр по course_id (без relationship): точное совпадение id, а не по
                // названию. У преподавателя под похожими/одинаковыми title лежат разные
                // курсы (когорты по годам) — поиск по названию выбирал не тот course_id.
                // Метка опции содержит ·#id, поэтому одноимённые курсы различимы, а набор
                // «56» матчит и название, и #56.
                Tables\Filters\SelectFilter::make('course_id')
                    ->label('Курс')
                    ->options(function (): array {
                        $q = Course::query()->orderBy('title');
                        $user = auth()->user();
                        if ($user && $user->isTeacher()) {
                            // Курсы препода: основной ИЛИ со-препод. Без teacher_id — пусто.
                            $q->forTeacher($user->teacher_id);
                        }

                        return $q->get()
                            ->mapWithKeys(fn (Course $c) => [$c->id => $c->title.' · #'.$c->id])
                            ->all();
                    })
                    ->searchable()
                    ->preload(),

                // Фильтр по группе курса. Нужен для перетаскивания порядка: reorder
                // включается только когда выбраны курс И группа, чтобы перенумерация
                // sort_order шла строго внутри одного потока. Sentinel 'none' = уроки
                // без группы (одиночные курсы / общие уроки), иначе их нельзя выбрать.
                Tables\Filters\SelectFilter::make('group_id')
                    ->label('Группа')
                    ->options(function ($livewire): array {
                        $courseId = data_get($livewire->getTableFilterState('course_id'), 'value');
                        $options = ['none' => 'Без группы (общие)'];
                        if ($courseId) {
                            $groups = Course::find($courseId)
                                ?->groups()->pluck('name', 'groups.id')->all() ?? [];
                            $options += $groups;
                        }

                        return $options;
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === 'none') {
                            return $query->whereNull('group_id');
                        }
                        if (filled($value)) {
                            return $query->where('group_id', $value);
                        }

                        return $query;
                    }),

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
                    // Массовая разметка половины блока: выделил уроки → задал 1 / 2 / весь блок.
                    Tables\Actions\BulkAction::make('setBlockHalf')
                        ->label('Проставить половину блока')
                        ->icon('heroicon-o-scissors')
                        ->form([
                            Forms\Components\Select::make('block_half')
                                ->label('Половина блока')
                                ->options([
                                    1 => '1-я половина',
                                    2 => '2-я половина',
                                ])
                                ->placeholder('Весь блок (очистить)')
                                ->helperText('Пусто = снять разметку (урок входит в целый блок).'),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            foreach ($records as $record) {
                                $record->update(['block_half' => $data['block_half'] ?? null]);
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    // Добавляем кнопку массового экспорта:
                    ExportBulkAction::make()
                        ->exporter(LessonExporter::class)
                        ->label('Экспорт для файлов'),
                ]),
            ])
            // Перетаскивание доступно только когда в фильтре выбраны конкретные курс И группа —
            // иначе Filament перенумеровал бы sort_order сразу по нескольким группам/курсам
            // и порядок размешался бы между ними. Нумерация ведётся внутри одного потока.
            ->reorderable(
                'sort_order',
                condition: fn ($livewire) => filled(data_get($livewire->getTableFilterState('course_id'), 'value'))
                    && filled(data_get($livewire->getTableFilterState('group_id'), 'value')),
            )
            ->defaultSort(fn (Builder $query) => $query
                ->orderBy('course_id')
                ->orderBy('group_id')
                ->orderBy('sort_order'));
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

    /**
     * H3308: имя файла на диске строит сервер, а не клиент. От оригинала
     * остаётся читаемый слаг (студенты видят basename в плеере), расширение
     * сохраняется, уникальность обеспечивает uniqid; путь-компоненты из имени
     * клиента исключены по построению (только [A-Za-z0-9._-], см. роут).
     */
    public static function safeStoredName($file): string
    {
        $original = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension() ?: pathinfo($original, PATHINFO_EXTENSION));
        $base = Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'file';

        return $base.'-'.uniqid().($ext !== '' ? '.'.$ext : '');
    }
}
