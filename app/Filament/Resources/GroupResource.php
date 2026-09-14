<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\GroupResource\Pages;
use App\Models\Group;
use App\Models\User;
use App\Services\ClassAttendanceService;
use App\Services\CuratorNotifier;
use App\Services\GroupRecruitmentNotifier;
use App\Services\WaitlistNotifier;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class GroupResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Group::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?string $navigationLabel = 'Учебные группы';

    protected static ?string $pluralModelLabel = 'Группы';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Данные группы')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->label('Название группы'),

                        Forms\Components\TextInput::make('slug')
                            ->label('Публичный ID (slug)')
                            ->helperText('Стабильный код ещё не запущенной группы для листа желающих. Пусто — из названия при создании.')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        Forms\Components\TextInput::make('telegram_chat_id')
                            ->label('Telegram chat_id группы')
                            ->helperText('ID чата группы в Telegram (напр. -1001234567890). Нужен для авто-постинга ссылки на занятие перед стартом. Пусто — не постим.')
                            ->maxLength(64),

                        // --- НОВЫЙ БЛОК: ИНТЕРАКТИВНЫЙ СПИСОК УЧЕНИКОВ ---
                        Forms\Components\Select::make('users')
                            ->relationship('users', 'name') // Автоматически подтягивает и сохраняет связи
                            ->multiple()                    // Позволяет выбрать нескольких
                            ->preload()                     // Подгружает первые результаты сразу
                            ->searchable()                  // Включает поиск по имени
                            ->label('Ученики в группе')
                            ->placeholder('Начните вводить имя ученика...')
                            ->helperText('Здесь вы можете посмотреть текущих участников, удалить их или добавить новых.'),
                    ]),

                // --- НАБОР КУРСОВ (H162) ---
                Forms\Components\Section::make('Набор')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Статус набора')
                            ->options(Group::STATUSES)
                            ->default('forming')
                            ->required(),

                        Forms\Components\TextInput::make('min_size')
                            ->label('Минимальный размер группы')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(255)
                            ->helperText('Порог «набрана». Пусто — размер не проверяем.'),

                        Forms\Components\DatePicker::make('planned_start_date')
                            ->label('Плановая дата старта')
                            ->native(false),

                        Forms\Components\DatePicker::make('start_date_override')
                            ->label('Перенос даты старта')
                            ->native(false)
                            ->helperText('Приоритет над плановой датой. Смена перезапускает отсчёт напоминания о недоборе и шлёт немедленное уведомление о переносе.'),
                    ])->columns(2),

                // --- КАНИКУЛЫ (H3790) ---
                Forms\Components\Section::make('Каникулы')
                    ->schema([
                        Forms\Components\Toggle::make('is_on_vacation')
                            ->label('Группа на каникулах')
                            ->helperText('Включённый флаг без даты = «дата уточняется»: в последнюю неделю августа бот спросит чат группы, когда возобновляются занятия, и при недоборе кворума за 2 недели предложит распустить группу.'),

                        Forms\Components\DatePicker::make('vacation_resume_date')
                            ->label('Дата выхода из каникул')
                            ->native(false)
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('is_on_vacation'))
                            ->helperText('Пока пусто — в публичном расписании группа помечается «дата выхода из каникул уточняется».'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Исправил дубль: теперь тут выводится реальный ID группы
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Публичный ID')
                    ->copyable()
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                // --- ОБНОВЛЕННАЯ КОЛОНКА УЧЕНИКОВ ---
                Tables\Columns\TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Учеников')
                    ->badge() // Делает цифру красивым бейджиком
                    ->color('info'), // Синий цвет

                // --- НАБОР КУРСОВ (H162) ---
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус набора')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Group::STATUSES[$state] ?? ($state ?? '—'))
                    ->color(fn (?string $state): string => match ($state) {
                        'forming' => 'warning',
                        'active' => 'success',
                        'archived' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('vacation_resume_date')
                    ->label('Каникулы')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('min_size')
                    ->label('Порог набора')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('planned_start_date')
                    ->label('План. старт')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_date_override')
                    ->label('Перенос')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус набора')
                    ->options(Group::STATUSES),
            ])
            ->actions([
                // Матрица посещаемости «студенты × занятия» за последние ~8 недель.
                Tables\Actions\Action::make('attendance_matrix')
                    ->label('Посещаемость')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
                    ->modalHeading(fn (Group $record): string => 'Посещаемость — '.$record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть')
                    ->modalWidth('7xl')
                    ->modalContent(fn (Group $record) => view('filament.group.attendance-matrix', [
                        'group' => $record,
                        'data' => app(ClassAttendanceService::class)
                            ->forGroup($record, now()->subWeeks(8), now()),
                    ])),

                // Предпочтения по времени из заявок набора (WaitlistEntry.preferred_schedule)
                // связанного Intake — прогон /prior-art подтвердил, что сбор предпочтений
                // уже существует (H230), отдельная таблица enrollment_preferences не нужна.
                Tables\Actions\Action::make('recruitment_preferences')
                    ->label('Предпочтения')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->visible(fn (Group $record): bool => $record->intake_id !== null)
                    ->modalHeading(fn (Group $record): string => 'Предпочтения набора — '.$record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть')
                    ->modalContent(function (Group $record) {
                        // Prefer entries pinned to this group ID; fall back to intake-wide.
                        $direct = $record->waitlistEntries()
                            ->whereIn('status', ['waiting', 'invited', 'recording_sent'])
                            ->get();
                        $entries = $direct->isNotEmpty()
                            ? $direct
                            : ($record->intake
                                ? $record->intake->waitlistEntries()
                                    ->whereIn('status', ['waiting', 'invited', 'recording_sent'])
                                    ->where(function ($q) use ($record) {
                                        $q->whereNull('group_id')->orWhere('group_id', $record->id);
                                    })
                                    ->get()
                                : collect());

                        return view('filament.group.recruitment-preferences', [
                            'group' => $record,
                            'entries' => $entries,
                        ]);
                    }),

                // Ручная фиксация даты старта при переносе — сбрасывает дедуп рассылки
                // (Group::booted()) и немедленно предупреждает уже присоединённых
                // студентов + кураторов, вместо ожидания следующего lead-window.
                Tables\Actions\Action::make('fix_start_date')
                    ->label('Зафиксировать дату')
                    ->icon('heroicon-o-calendar-days')
                    ->color('warning')
                    ->visible(fn (Group $record): bool => $record->status === 'forming')
                    ->form([
                        Forms\Components\DatePicker::make('start_date_override')
                            ->label('Новая дата старта')
                            ->native(false)
                            ->required(),
                    ])
                    ->action(function (Group $record, array $data): void {
                        $record->update(['start_date_override' => $data['start_date_override']]);

                        if (! $record->isRecruited()) {
                            app(GroupRecruitmentNotifier::class)->notifyShortfall($record);
                            app(CuratorNotifier::class)->groupUnderEnrolled($record);
                        }

                        // Лист ожидания всегда узнаёт о переносе — независимо от набора.
                        app(WaitlistNotifier::class)->notify(
                            $record,
                            WaitlistNotifier::KIND_TRANSFER,
                            WaitlistNotifier::transferText($record, Carbon::parse($data['start_date_override'])),
                        );
                    }),

                // Ручная рассылка статуса листу ожидания (H3327): куратор решает
                // момент и видит текст перед отправкой; доставку ведут мессенджер-алерты,
                // аудит каждой отправки — в waitlist_outreaches. Админ подстраховывает:
                // экран доступен только админам (AdminOnly).
                Tables\Actions\Action::make('notify_waitlist')
                    ->label('Сообщить листу ожидания')
                    ->icon('heroicon-m-megaphone')
                    ->color('info')
                    ->form([
                        Forms\Components\Textarea::make('text')
                            ->label('Текст сообщения')
                            ->rows(4)
                            ->default(fn (Group $record): string => app(WaitlistNotifier::class)->statusText($record))
                            ->required(),
                    ])
                    ->action(function (Group $record, array $data): void {
                        $result = app(WaitlistNotifier::class)->notify(
                            $record,
                            WaitlistNotifier::KIND_MANUAL,
                            $data['text'],
                            auth()->user(),
                        );

                        Notification::make()
                            ->title('Лист ожидания уведомлён')
                            ->body("Доставлено в мессенджеры: {$result['messengers']}. Без кабинета (вручную): {$result['manual']}.")
                            ->send();
                    }),

                // Грант «проверяющий ↔ группа» (H1729): кто, кроме преподавателя
                // курса, видит и проверяет домашки этой группы. Раздаёт только
                // админ — преподаватель себе грант выдать не может.
                Tables\Actions\Action::make('reviewers')
                    ->label('Проверяющие')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading(fn (Group $record): string => 'Проверяющие домашек — '.$record->name)
                    ->modalSubmitActionLabel('Сохранить')
                    ->fillForm(fn (Group $record): array => [
                        'reviewer_ids' => $record->reviewers()->pluck('users.id')->all(),
                        'can_review' => true,
                        'notify' => true,
                    ])
                    ->form([
                        Forms\Components\Select::make('reviewer_ids')
                            ->label('Кто проверяет')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => User::query()
                                ->whereIn('role', [Roles::TEACHER, Roles::ADMIN, Roles::SUPER_ADMIN])
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->helperText('Грант даёт доступ к домашкам и составу этой группы. На зарплату не влияет.'),

                        Forms\Components\Toggle::make('can_review')
                            ->label('Может выносить вердикт')
                            ->helperText('Выключено — только смотреть и комментировать, принять/вернуть не сможет.')
                            ->default(true),

                        Forms\Components\Toggle::make('notify')
                            ->label('Оповещать о новых работах')
                            ->default(true),
                    ])
                    ->action(function (Group $record, array $data): void {
                        $pivot = [
                            'can_review' => (bool) ($data['can_review'] ?? true),
                            'notify' => (bool) ($data['notify'] ?? true),
                            'assigned_by' => auth()->id(),
                            'assigned_at' => now(),
                        ];

                        $record->reviewers()->sync(
                            collect($data['reviewer_ids'] ?? [])
                                ->mapWithKeys(fn ($id) => [(int) $id => $pivot])
                                ->all()
                        );
                    }),

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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
            // 'create' => Pages\CreateGroup::route('/create'),
            // 'edit' => Pages\EditGroup::route('/{record}/edit'),
        ];
    }
}
