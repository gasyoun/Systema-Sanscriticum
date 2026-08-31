<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CourseResource\Pages;
use App\Models\Course;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Services\ProgrammeShellGraph;
use App\Support\RoleGate;
use App\Support\Roles;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use FilamentTiptapEditor\TiptapEditor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public static function canCreate(): bool
    {
        // Учитель не создаёт курсы — только админ.
        return RoleGate::adminOnly();
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

        // Учитель может редактировать курс, где он основной ИЛИ со-препод. Без
        // teacher_id — никаких прав (isTaughtBy(null) === false).
        return $user->isTeacher()
            && $record->isTaughtBy($user->teacher_id);
    }

    public static function canDelete($record): bool
    {
        return RoleGate::adminOnly();
    }

    public static function canDeleteAny(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();
        if ($user && $user->isTeacher()) {
            // Курсы препода: основной ИЛИ со-препод. Без teacher_id — ничего
            // (scopeForTeacher(null) → whereRaw('1=0')).
            $query->forTeacher($user->teacher_id);
        }

        return $query;
    }

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?string $navigationLabel = 'Курсы';

    protected static ?string $pluralModelLabel = 'Курсы';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // ==========================================
                // БЛОК: ИНФОРМАЦИЯ О КУРСЕ
                // ==========================================
                Forms\Components\Section::make('Информация о курсе')
                    ->schema([
                        // БЛОК 1: Название и Slug
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->required()
                                    ->label('Название курса')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (string $operation, $state, Forms\Set $set) => $operation === 'create' ? $set('slug', Str::slug($state)) : null
                                    ),

                                Forms\Components\TextInput::make('slug')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->label('URL-адрес (slug)')
                                    ->helperText('Короткий канон: кабинет /c/{slug}/u/{id}, витрина /k/{slug}. Пример: hindi-2_sb1300-2026. При смене старый slug сохраняется как 301-алиас.'),

                                Forms\Components\TextInput::make('course_family')
                                    ->label('Семья потоков')
                                    ->maxLength(190)
                                    ->placeholder('kasmirskii-sivaizm')
                                    ->helperText('Заполняется автоматически по названию (команда courses:backfill-families); ручное значение всегда побеждает. Курсы с одинаковым значением встают в одну таблицу «Потоки курса». Пусто — курс вне семьи.'),
                            ]),

                        // БЛОК 2: Описание
                        TiptapEditor::make('description')
                            ->label('Описание')
                            ->profile('simple')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('chat_url')
                            ->label('Ссылка на чат курса')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://vk.me/join/... или https://t.me/...')
                            ->helperText('Универсальная ссылка на чат курса (VK, Telegram, Discord). Если пусто — кнопка в кабинете студента не показывается.')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('zoom_link')
                            ->label('Zoom-ссылка курса (единая)')
                            ->url()
                            ->maxLength(1024)
                            ->placeholder('https://us02web.zoom.us/j/000000000?pwd=...')
                            ->prefixIcon('heroicon-m-video-camera')
                            ->helperText('Постоянная ссылка на конференцию курса (создаётся вручную на zoom.us). Генератор расписания подставит её в каждое занятие; meeting_id для учёта посещаемости извлекается автоматически.')
                            ->columnSpanFull(),

                        // БЛОК 3: Статистика
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('lessons_count')
                                    ->numeric()
                                    ->label('Количество уроков')
                                    ->placeholder('Например: 12')
                                    ->default(12),

                                Forms\Components\TextInput::make('hours_count')
                                    ->numeric()
                                    ->label('Академических часов')
                                    ->placeholder('Например: 24')
                                    ->default(24),
                            ]),

                        // БЛОК 4: Доступ и Видимость
                        // Только админ — учитель не должен раздавать доступ группам.
                        Forms\Components\Select::make('groups')
                            ->multiple()
                            ->relationship('groups', 'name')
                            ->preload()
                            ->searchable()
                            ->label('Доступ для групп')
                            ->helperText('Студенты из выбранных групп увидят этот курс у себя в кабинете.')
                            ->columnSpanFull()
                            ->visible(fn () => RoleGate::adminOnly())
                            ->dehydrated(fn () => RoleGate::adminOnly()),

                        // --- ИЗМЕНЕНИЕ ЗДЕСЬ: Объединили два свитча в сетку ---
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Toggle::make('is_visible')
                                    ->label('Показывать на сайте')
                                    ->default(true)
                                    ->onColor('success')
                                    ->offColor('danger'),

                                Forms\Components\Toggle::make('is_elective')
                                    ->label('Это факультатив')
                                    ->helperText('Курс участвует в программе лояльности (скидки за объем)')
                                    ->default(false)
                                    ->onColor('warning'), // Золотой цвет для выделения

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Доступен студентам в ЛК')
                                    ->helperText('Если выключить — курс исчезнет из личных кабинетов студентов, даже если они его купили. Используйте для архивации.')
                                    ->default(true)
                                    ->onColor('success')
                                    ->inline(false),

                                Forms\Components\Toggle::make('is_completed')
                                    ->label('Курс завершён (продаём записи)')
                                    ->helperText('Поток закончился, записи опубликованы, повторного набора нет. Вместе с тарифом-записью и включённым флагом course_recordings_sales переключает лендинг с «Записаться» на «Купить запись».')
                                    ->default(false)
                                    ->onColor('warning')
                                    ->inline(false),

                                Forms\Components\Toggle::make('never_repeat')
                                    ->label('Живой повтор не планируется')
                                    ->helperText('Известно заранее: живой поток больше не набираем. Куратор говорит «повтора не будет, есть запись» — не «уточню».')
                                    ->default(false)
                                    ->onColor('danger')
                                    ->inline(false),
                            ]),

                        // Новизна для анонсов «только новые курсы» (MG 31-08-2026):
                        // no_repeat дублирует never_repeat для витрины; при выборе
                        // no_repeat флаг never_repeat проставляется автоматически.
                        Forms\Components\Select::make('novelty')
                            ->label('Новизна (для анонсов)')
                            ->options(Course::NOVELTIES)
                            ->default('usual')
                            ->helperText('«Впервые» / «Возвращается» попадают в анонсы новых курсов; «Повтора не будет» — нет (проставляет «Живой повтор не планируется»).')
                            ->afterStateUpdated(function (string $state, Forms\Set $set): void {
                                if ($state === 'no_repeat') {
                                    $set('never_repeat', true);
                                }
                            }),

                        // БЛОК: КАТЕГОРИИ И ФОРМАТ
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('categories')
                                    ->label('Категории')
                                    ->multiple()
                                    ->relationship('categories', 'name')
                                    ->preload()
                                    ->searchable()
                                    ->createOptionForm([
                                        // Создание категории прямо из формы курса — быстрый сценарий
                                        Forms\Components\TextInput::make('name')
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('slug', Str::slug($state))
                                            ),
                                        Forms\Components\TextInput::make('slug')
                                            ->required()
                                            ->unique('categories', 'slug'),
                                        Forms\Components\TextInput::make('icon')
                                            ->placeholder('fa-om'),
                                        Forms\Components\ColorPicker::make('color'),
                                    ])
                                    ->helperText('Курс может относиться к нескольким категориям (Философия, Лингвистика и т.д.)')
                                    ->columnSpanFull(),

                                Forms\Components\Radio::make('format')
                                    ->label('Формат курса')
                                    ->options([
                                        'live' => '🔴 Идёт сейчас (live-поток)',
                                        'recorded' => '📼 В записи (доступен в любое время)',
                                    ])
                                    ->default('recorded')
                                    ->required()
                                    ->inline(false)
                                    ->columnSpanFull(),

                                Forms\Components\Select::make('level')
                                    ->label('Уровень')
                                    ->options(Course::LEVELS)
                                    ->placeholder('Не задан')
                                    ->helperText('«С нуля» / «Продолжающим» / «Продвинутый» — бейдж на карточке и фильтр каталога. Пусто — бейджа нет и курс не участвует в фильтре по уровню.')
                                    ->nullable()
                                    ->columnSpanFull(),

                                Forms\Components\Select::make('predecessor_course_id')
                                    ->label('Начало программы (предшественник)')
                                    ->relationship(
                                        'predecessor',
                                        'title',
                                        fn ($query) => $query->orderBy('title'),
                                        ignoreRecord: true,
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->helperText('Если этот поток — продолжение, укажите курс с более ранними занятиями. Цепочка строится по одному предшественнику на поток (гр. 5 → гр. 3 → гр. 2). Цикл (A→B→A) сохранить нельзя. Баннер в кабинете — одно звено; плейлист читает всю цепочку.')
                                    ->rule(function (?Course $record): Closure {
                                        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                            if ($value === null || $value === '' || $record === null) {
                                                return;
                                            }
                                            if (app(ProgrammeShellGraph::class)->wouldCycle((int) $record->id, (int) $value)) {
                                                $fail('Цепочка курсов не может содержать цикл (A→B→A). Выберите другого предшественника.');
                                            }
                                        };
                                    })
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('continues_from_lesson')
                                    ->label('С какого занятия этот поток')
                                    ->numeric()
                                    ->minValue(2)
                                    ->maxValue(999)
                                    ->nullable()
                                    ->helperText('Например 13 — «записи с 13-го занятия». Пусто — попробуем вывести из заголовка первого урока.')
                                    ->columnSpanFull(),
                            ]),
                    ]),

                // ==========================================
                // БЛОК: ПРОДАЮЩАЯ СТРАНИЦА (ЛЕНДИНГ)
                // ==========================================
                Forms\Components\Section::make('🛒 Продающая страница')
                    ->description('Дополнительные блоки публичной страницы курса. Пустые поля — соответствующий блок просто не показывается.')
                    ->schema([
                        Forms\Components\TagsInput::make('audience')
                            ->label('Для кого этот курс')
                            ->helperText('Каждый пункт — отдельный тег (Enter). Пусто — блок скрыт.')
                            ->placeholder('Добавить пункт')
                            ->columnSpanFull(),

                        Forms\Components\TagsInput::make('outcomes')
                            ->label('Чему научатся')
                            ->helperText('Результаты обучения. Каждый пункт — отдельный тег (Enter).')
                            ->placeholder('Добавить пункт')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('tech_requirements')
                            ->label('Технические требования (переопределение)')
                            ->helperText('Пусто — показывается общий текст по умолчанию.')
                            ->rows(3)
                            ->columnSpanFull(),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('meta_title')
                                    ->label('SEO: заголовок (title)')
                                    ->helperText('Пусто — берётся название курса.')
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('meta_description')
                                    ->label('SEO: описание (meta description)')
                                    ->helperText('Пусто — берётся усечённое описание курса.')
                                    ->maxLength(255),
                            ]),

                        Forms\Components\TextInput::make('cta_subject')
                            ->label('Тема для финального CTA (винительный падеж)')
                            ->helperText('Например «философию», «хинди», «календарь». Пусто — используется «Готовы начать изучать санскрит?».')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),

                // ==========================================
                // БЛОК: ПРЕДОПЛАТА ЗА БРОНЬ
                // ==========================================
                Forms\Components\Section::make('📌 Предоплата за бронь курса')
                    ->description('Сумма, за которую студент может «забронировать» этот курс. Будет зачтена при последующей оплате тарифа. Кнопка «Забронировать» показывается на витрине только если поле заполнено И глобальный тогл в маркетинг-настройках включён.')
                    ->schema([
                        Forms\Components\TextInput::make('deposit_amount')
                            ->label('Сумма депозита, ₽')
                            ->helperText('Пусто/0 — бронь для этого курса не предлагается, даже если глобально депозиты включены.')
                            ->numeric()
                            ->minValue(0)
                            ->step('0.01')
                            ->suffix('₽'),
                    ])
                    ->collapsible(),

                // ==========================================
                // БЛОК: ПРОБНОЕ ЗАНЯТИЕ
                // ==========================================
                Forms\Components\Section::make('🎟 Пробное занятие')
                    ->description('Платное пробное занятие с витрины: купивший попадает на выбранное занятие из расписания — предстоящее (живьём по Zoom) или прошедшее (его запись). На сохранении курса создаётся урок-заготовка — n8n позже дозальёт в неё запись. Кнопка показывается, если задана цена И выбрано событие. Сумма зачтётся при покупке курса.')
                    ->schema([
                        Forms\Components\TextInput::make('trial_price')
                            ->label('Цена пробного, ₽')
                            ->helperText('Пусто/0 — пробное занятие не продаётся.')
                            ->numeric()
                            ->minValue(0)
                            ->step('0.01')
                            ->suffix('₽'),

                        Forms\Components\Select::make('trial_schedule_id')
                            ->label('Занятие из расписания (живое или запись)')
                            ->helperText('Предстоящее событие — пробное откроет живое занятие (Zoom-ссылка и дата берутся из него). Прошедшее событие — пробное откроет его запись (когда она залита в урок-заготовку). Группа события должна совпадать с той, что присылает n8n.')
                            ->options(fn (?Course $record): array => $record
                                ? Schedule::query()
                                    ->where('course_id', $record->id)
                                    ->orderByDesc('start')
                                    ->get()
                                    ->mapWithKeys(fn (Schedule $s) => [
                                        $s->id => $s->start->format('d.m.Y H:i').' — '.$s->title
                                            .($s->start->isPast() ? ' (прошло — запись)' : ''),
                                    ])
                                    ->all()
                                : [])
                            ->searchable()
                            ->preload()
                            ->nullable(),
                    ])
                    ->columns(2)
                    ->collapsible(),

                // ==========================================
                // БЛОК: ПРЕПОДАВАТЕЛЬ И ЗАРПЛАТА
                // ==========================================
                // canEdit() пускает учителя на свой курс (для правки контента),
                // но teacher_id/salary_type/salary_value — admin-only: иначе
                // учитель сможет переназначить курс или поднять себе ставку.
                Forms\Components\Section::make('Преподаватель и Зарплата')
                    ->visible(fn () => RoleGate::adminOnly())
                    ->schema([
                        Forms\Components\Select::make('teacher_id')
                            ->label('Преподаватель')
                            ->relationship('teacher', 'name')
                            ->searchable()
                            ->preload(),

                        Forms\Components\Select::make('salary_type')
                            ->label('Схема расчета')
                            ->options([
                                'percent' => 'Процент от продаж всего курса (%)',
                                'fix_per_student' => 'Фикс за каждого студента (₽)',
                                'fix_total' => 'Фикс за весь курс (₽)',
                                'percent_per_block' => 'Процент с каждого блока (%)',
                                'fix_per_block' => 'Фикс за каждый блок (₽)', // <--- ИЗМЕНИЛИ ТЕКСТ ЗДЕСЬ
                            ]),

                        Forms\Components\TextInput::make('salary_value')
                            ->label('Ставка (Цифра)')
                            ->numeric()
                            ->helperText('Например: 30 (для 30%) или 5000 (для 5000 руб)'),

                        // Второй (и далее) преподаватель: полный доступ к курсу +
                        // СВОИ условия ЗП. Начисление независимое — каждый от той же
                        // выручки по своей ставке. Хранится в pivot course_teacher
                        // (синхронизируется в CreateCourse/EditCourse).
                        Forms\Components\Repeater::make('co_teachers')
                            ->label('Второй преподаватель (доступ наравне + своя ЗП)')
                            ->dehydrated(false)
                            ->columnSpanFull()
                            ->schema([
                                Forms\Components\Select::make('teacher_id')
                                    ->label('Преподаватель')
                                    ->options(function (Forms\Get $get) {
                                        $primary = $get('../../teacher_id');

                                        return Teacher::query()
                                            ->when($primary, fn ($q) => $q->whereKeyNot($primary))
                                            ->orderBy('name')
                                            ->pluck('name', 'id');
                                    })
                                    ->searchable()
                                    ->required()
                                    ->distinct(),
                                Forms\Components\Select::make('salary_type')
                                    ->label('Схема расчёта')
                                    ->options([
                                        'percent' => 'Процент от продаж всего курса (%)',
                                        'fix_per_student' => 'Фикс за каждого студента (₽)',
                                        'fix_total' => 'Фикс за весь курс (₽)',
                                        'percent_per_block' => 'Процент с каждого блока (%)',
                                        'fix_per_block' => 'Фикс за каждый блок (₽)',
                                    ])
                                    ->required(),
                                Forms\Components\TextInput::make('salary_value')
                                    ->label('Ставка (Цифра)')
                                    ->numeric()
                                    ->helperText('30 (для 30%) или 5000 (₽)'),
                            ])
                            ->columns(3)
                            ->addActionLabel('Добавить преподавателя')
                            ->defaultItems(0),
                    ])->columns(3),

                // ==========================================
                // БЛОК: ТАРИФЫ И ЦЕНЫ
                // ==========================================
                // Тарифы — admin-only: иначе учитель сможет переписать цену
                // своего курса или включить/выключить тарифы.
                Forms\Components\Section::make('Тарифы и цены')
                    ->visible(fn () => RoleGate::adminOnly())
                    ->schema([
                        Forms\Components\Repeater::make('tariffs')
                            ->relationship('tariffs')
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('Название тарифа (например: Блок 1, Полный курс)')
                                    ->required(),

                                Forms\Components\Select::make('type')
                                    ->label('Тип доступа')
                                    ->options([
                                        'full' => 'Весь курс целиком',
                                        'block' => 'Отдельный блок',
                                    ])
                                    ->required(),

                                Forms\Components\TextInput::make('block_number')
                                    ->label('Номер блока')
                                    ->numeric(),

                                Forms\Components\TextInput::make('price')
                                    ->label('Цена (₽)')
                                    ->numeric()
                                    ->required(),

                                Forms\Components\TextInput::make('old_price')
                                    ->label('Старая цена (₽)')
                                    ->numeric(),

                                Forms\Components\Textarea::make('description')
                                    ->label('Описание тарифа')
                                    ->rows(2),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Активен')
                                    ->default(true),

                                Forms\Components\Toggle::make('is_recording')
                                    ->label('Тариф-запись (evergreen)')
                                    ->helperText('Продаёт запись завершённого курса, а не участие в живом потоке.')
                                    ->default(false),
                            ])
                            ->columns(2)
                            ->addActionLabel('Добавить тариф')
                            ->reorderable()
                            ->collapsible(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Course $record) => Str::limit(strip_tags($record->description ?? ''), 50))
                    ->label('Название'),

                Tables\Columns\TextColumn::make('groups.name')
                    ->label('Доступен группам')
                    ->badge()
                    ->color('info')
                    ->limitList(2),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                // --- НОВАЯ КОЛОНКА В ТАБЛИЦЕ ---
                Tables\Columns\IconColumn::make('is_elective')
                    ->boolean()
                    ->label('Факультатив'),

                Tables\Columns\IconColumn::make('is_visible')
                    ->boolean()
                    ->label('Активен'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активен в ЛК')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                // --- НОВЫЙ ФИЛЬТР ПО ФАКУЛЬТАТИВАМ ---
                Tables\Filters\TernaryFilter::make('is_elective')
                    ->label('Тип курса')
                    ->trueLabel('Только факультативы')
                    ->falseLabel('Только базовые курсы')
                    ->placeholder('Все курсы'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            CourseResource\RelationManagers\FaqsRelationManager::class,
            CourseResource\RelationManagers\TestimonialsRelationManager::class,
            CourseResource\RelationManagers\CertificateMilestonesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourses::route('/'),
            'create' => Pages\CreateCourse::route('/create'),
            'edit' => Pages\EditCourse::route('/{record}/edit'),
        ];
    }

    /**
     * Синхронизировать со-преподавателей курса (pivot course_teacher) из строк
     * репитера формы. Основного препода (course.teacher_id) в pivot не пишем.
     * Вызывается из CreateCourse::afterCreate и EditCourse::afterSave.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function syncCoTeachers(Course $course, array $rows): void
    {
        $pivot = collect($rows)
            ->filter(fn ($r) => ! empty($r['teacher_id']))
            ->reject(fn ($r) => (int) $r['teacher_id'] === (int) $course->teacher_id)
            ->mapWithKeys(fn ($r) => [(int) $r['teacher_id'] => [
                'salary_type' => $r['salary_type'] ?? null,
                'salary_value' => ($r['salary_value'] ?? '') !== '' ? $r['salary_value'] : null,
            ]])
            ->all();

        $course->teachers()->sync($pivot);
    }
}
