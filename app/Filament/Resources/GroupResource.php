<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\GroupResource\Pages;
use App\Models\Group;
use App\Models\User;
use App\Services\ClassAttendanceService;
use App\Services\CuratorNotifier;
use App\Services\GroupRecruitmentNotifier;
use App\Services\Telegram\ZapisiChatMemberException;
use App\Services\Telegram\ZapisiChatMemberService;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
                    ->modalContent(fn (Group $record) => view('filament.group.recruitment-preferences', [
                        'group' => $record,
                        'entries' => $record->intake
                            ? $record->intake->waitlistEntries()
                                ->whereIn('status', ['waiting', 'invited', 'recording_sent'])
                                ->get()
                            : collect(),
                    ])),

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

                Tables\Actions\Action::make('check_zapisi_rights')
                    ->label('Права zapisi')
                    ->icon('heroicon-o-shield-check')
                    ->color('gray')
                    ->visible(fn (Group $record): bool => filled($record->telegram_chat_id))
                    ->action(function (Group $record, ZapisiChatMemberService $zapisi): void {
                        $rights = $zapisi->botRightsInChat((string) $record->telegram_chat_id);
                        Notification::make()
                            ->title($rights['ok'] ? 'Zapisi может исключать' : 'Zapisi не может исключать')
                            ->body($rights['detail'])
                            ->{$rights['ok'] ? 'success' : 'danger'}()
                            ->send();
                    }),

                // Soft kick из учебного TG-чата через @zapisi_ORSbot (ban+unban).
                // LMS-состав не трогаем. Бот должен быть админом с can_restrict_members.
                Tables\Actions\Action::make('kick_from_telegram')
                    ->label('Исключить из TG')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Group $record): bool => filled($record->telegram_chat_id))
                    ->form(fn (Group $record): array => [
                        Forms\Components\Select::make('user_id')
                            ->label('Ученик')
                            ->required()
                            ->searchable()
                            ->options(
                                $record->activeUsers()
                                    ->orderBy('name')
                                    ->get(['users.id', 'users.name', 'users.telegram_id', 'users.telegram_username'])
                                    ->mapWithKeys(function (User $u) {
                                        $tg = filled($u->telegram_id)
                                            ? 'TG:'.$u->telegram_id.($u->telegram_username ? ' @'.$u->telegram_username : '')
                                            : 'Telegram не привязан';
                                        $label = $u->name.' — '.$tg;

                                        return [(int) $u->id => $label];
                                    })
                                    ->all()
                            )
                            ->helperText('Исключение только из Telegram-чата (soft kick). Состав LMS не меняется. Нужна привязка Telegram у ученика.'),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading(fn (Group $record): string => 'Исключить из TG — '.$record->name)
                    ->modalSubmitActionLabel('Исключить')
                    ->action(function (Group $record, array $data, ZapisiChatMemberService $zapisi): void {
                        $user = User::query()->find($data['user_id'] ?? null);
                        if (! $user) {
                            Notification::make()->title('Ученик не найден')->danger()->send();

                            return;
                        }
                        if (blank($user->telegram_id)) {
                            Notification::make()
                                ->title('Нет telegram_id')
                                ->body('Ученик должен привязать Telegram в кабинете.')
                                ->danger()
                                ->send();

                            return;
                        }
                        try {
                            $zapisi->kick(
                                (string) $record->telegram_chat_id,
                                $user->telegram_id,
                                auth()->id() ? (int) auth()->id() : null,
                            );
                            Notification::make()
                                ->title('Исключён из Telegram-чата')
                                ->body($user->name)
                                ->success()
                                ->send();
                        } catch (ZapisiChatMemberException $e) {
                            Notification::make()
                                ->title('Не удалось исключить')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
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
