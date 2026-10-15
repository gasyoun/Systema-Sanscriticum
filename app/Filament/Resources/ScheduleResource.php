<?php

namespace App\Filament\Resources;

use App\Filament\Actions\RemindPaymentInChatAction;
use App\Filament\Resources\ScheduleResource\Pages;
use App\Models\Course;
use App\Models\Schedule;
use App\Services\ClassAttendanceService;
use App\Services\OpenLessonCards\OpenLessonCardBuilder;
use App\Services\OpenLessonCards\OpenLessonCardSender;
use App\Services\Schedule\ScheduleMover;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ScheduleResource extends Resource
{
    protected static ?string $model = Schedule::class;

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public static function canCreate(): bool
    {
        if (RoleGate::any(Roles::ADMIN)) {
            return true;
        }
        // Учитель может создавать события только при заполненном teacher_id —
        // иначе скоуп по course_id даст 1=0 и сохранение всё равно упадёт.
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
            $query->whereHas('course', fn (Builder $q) => $q->forTeacher($user->teacher_id));
        }

        return $query;
    }

    protected static ?string $navigationIcon = 'heroicon-o-calendar'; // <-- Иконка календаря

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?string $navigationLabel = 'Расписание';

    protected static ?string $pluralModelLabel = 'Расписание';

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /**
     * Схема формы события расписания. Вынесена отдельно, чтобы её переиспользовал
     * календарный виджет (ScheduleCalendarWidget) для модалок создания/редактирования —
     * та же валидация course_id по teacher_id, что и в обычной форме.
     *
     * @return array<int, Component>
     */
    public static function formSchema(): array
    {
        return [
            Forms\Components\Section::make('Событие')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Название события')
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('Описание / Ссылка')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('link')
                        ->label('Ссылка на Zoom / Google Meet')
                        ->placeholder('https://zoom.us/j/...')
                        ->url()
                        ->maxLength(1024)
                        ->prefixIcon('heroicon-m-video-camera')
                        ->helperText('Обычно подставляется автоматически из единой Zoom-ссылки курса при генерации потока.')
                        ->columnSpanFull(),

                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\DateTimePicker::make('start')
                                ->label('Начало')
                                ->required(),
                            Forms\Components\DateTimePicker::make('end')
                                ->label('Окончание (необязательно)'),
                        ]),

                    // H4328: обзорное занятие — не участвует в нумерации 1–N
                    // в полном посте расписания, идёт первым блоком.
                    Forms\Components\Toggle::make('is_overview')
                        ->label('Обзорное занятие (не в счёт остальных)')
                        ->helperText('В посте расписания выводится отдельным блоком «Обзорное занятие (не в счет N)» и не нумеруется.')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Настройки')
                ->schema([
                    Forms\Components\Select::make('course_id')
                        ->label('Курс')
                        ->relationship(
                            name: 'course',
                            titleAttribute: 'title',
                            modifyQueryUsing: function (Builder $query): void {
                                // Учитель видит в селекте свои курсы (основной ИЛИ
                                // со-препод); админ — все. Без teacher_id — пусто.
                                $user = auth()->user();
                                if ($user && $user->isTeacher()) {
                                    $query->forTeacher($user->teacher_id);
                                }
                            },
                        )
                        // Серверная валидация: relationship-Select по умолчанию не накладывает
                        // where на Rule::exists, поэтому учитель мог бы через POST передать
                        // чужой course_id. Дополняем явным правилом по teacher_id.
                        ->rule(fn () => Course::teacherCourseValidationRule())
                        ->searchable()
                        ->preload()
                        ->placeholder('Без привязки')
                        ->required(fn (): bool => (bool) auth()->user()?->isTeacher())
                        ->helperText('Преподаватель видит и правит только события своих курсов.'),

                    Forms\Components\Select::make('group_id')
                        ->relationship('group', 'name')
                        ->label('Для группы (Пусто = для всех)')
                        ->searchable()
                        ->preload(),

                    Forms\Components\ColorPicker::make('color')
                        ->label('Цвет метки')
                        ->default('#3788d8'),
                ])->columns(2),

            // Карточка открытого занятия в @samskrte_bot: фото + «✅ Я иду»,
            // анонс / утро / ссылка Zoom. Только админ: карточка обещает
            // «участие бесплатное» и раздаёт ссылку всем подписчикам бота.
            Forms\Components\Section::make('Анонс в боте @samskrte_bot')
                ->description('Открытое бесплатное занятие: карточка с кнопкой «✅ Я иду» уйдёт подписчикам бота за 2–3 дня и в день занятия, ссылка Zoom — нажавшим «Я иду» за 30 минут до начала.')
                ->visible(fn (): bool => RoleGate::any(Roles::ADMIN))
                ->collapsible()
                ->schema([
                    Forms\Components\Toggle::make('bot_card_enabled')
                        ->label('Открытое занятие: анонс в боте')
                        ->helperText('Включайте только для бесплатных занятий — платный Zoom не раздавать.')
                        ->live()
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('card_image_path')
                        ->label('Баннер карточки (необязательно)')
                        ->helperText('Пусто — баннер курса 16:9 / 4:3 или обложка витрины.')
                        ->image()
                        ->disk('public')
                        ->directory('open-lesson-cards')
                        ->maxSize(5120)
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('bot_card_enabled')),

                    Forms\Components\Textarea::make('card_note')
                        ->label('Приписка к карточке (необязательно)')
                        ->placeholder('Захватите свои вопросы — в конце разберём их вживую.')
                        ->maxLength(300)
                        ->rows(2)
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('bot_card_enabled')),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start', 'asc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->striped()
            ->groups([
                // Группируем по календарному дню вручную (без ->date()): Filament при ->date()
                // прогоняет заголовок группы через Carbon::parse(), а наш заголовок —
                // локализованная строка («сбт, 06 июня»), которую Carbon распарсить не может.
                Tables\Grouping\Group::make('start')
                    ->label('Дата')
                    ->titlePrefixedWithLabel(false)
                    ->getKeyFromRecordUsing(fn (Schedule $r): ?string => $r->start?->toDateString())
                    ->getTitleFromRecordUsing(fn (Schedule $r): string => self::humanizeDateHeader($r->start))
                    ->groupQueryUsing(fn (Builder $query) => $query->groupByRaw('date(start)'))
                    ->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderBy('start', $direction))
                    ->scopeQueryByKeyUsing(fn (Builder $query, string $key) => $query->whereDate('start', $key))
                    ->collapsible(),
            ])
            ->defaultGroup('start')
            ->columns([
                Tables\Columns\TextColumn::make('start')
                    ->label('Время')
                    ->width('15%')
                    ->formatStateUsing(function (Schedule $r): string {
                        $from = $r->start?->format('H:i') ?? '—';
                        $to = $r->end?->format('H:i');

                        return $to ? "{$from} – {$to}" : $from;
                    })
                    ->description(fn (Schedule $r): ?string => self::timeBadgeLabel($r))
                    ->color(fn (Schedule $r): string => self::timeBadgeColor($r))
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Событие')
                    ->width('50%')
                    ->wrap()
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Schedule $r): ?string => $r->description
                        ? Str::limit($r->description, 120)
                        : null)
                    ->url(fn (Schedule $r): ?string => $r->link)
                    ->openUrlInNewTab(),

                Tables\Columns\TextColumn::make('group.name')
                    ->label('Группа')
                    ->width('25%')
                    ->badge()
                    ->color(fn (?string $state): string => $state ? 'primary' : 'gray')
                    ->formatStateUsing(fn (?string $state): string => $state ?? 'Для всех')
                    ->icon(fn (?string $state): string => $state ? 'heroicon-m-user-group' : 'heroicon-m-globe-alt'),

                // Карточка в @samskrte_bot: число «✅ Я иду»; лампочка — обзорное
                // без анонса (кандидат, решает админ — бесплатно ли оно).
                Tables\Columns\TextColumn::make('open_lesson_rsvps_count')
                    ->label('В боте')
                    ->counts(['openLessonRsvps' => fn (Builder $q) => $q->whereNotNull('going_at')])
                    ->formatStateUsing(fn (Schedule $r, $state): string => $r->bot_card_enabled ? "🎙 Я иду: {$state}" : '💡 кандидат')
                    ->tooltip(fn (Schedule $r): ?string => ! $r->bot_card_enabled && $r->is_overview ? 'Обзорное занятие без анонса в боте' : null)
                    ->visible(fn (): bool => RoleGate::any(Roles::ADMIN))
                    ->getStateUsing(fn (Schedule $r) => ($r->bot_card_enabled || $r->is_overview) ? (int) ($r->open_lesson_rsvps_count ?? 0) : null)
                    ->badge()
                    ->color(fn (Schedule $r): string => $r->bot_card_enabled ? 'success' : 'warning')
                    ->toggleable()
                    ->placeholder('—'),

                // Посещаемость вебинара (Zoom participant-вебхуки, Фаза 3).
                Tables\Columns\TextColumn::make('attendances_count')
                    ->label('Участников')
                    ->counts('attendances')
                    ->badge()
                    ->color('success')
                    ->icon('heroicon-m-users')
                    ->toggleable()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('time_range')
                    ->label('Период')
                    ->placeholder('Все')
                    ->trueLabel('Будущие')
                    ->falseLabel('Прошедшие')
                    ->default(true)
                    ->queries(
                        true: fn ($query) => $query->where('start', '>=', now()),
                        false: fn ($query) => $query->where('start', '<', now()),
                        blank: fn ($query) => $query,
                    ),

                Tables\Filters\SelectFilter::make('group_id')
                    ->label('Группа')
                    ->relationship('group', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('course_id')
                    ->label('Курс')
                    ->relationship('course', 'title')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\Action::make('open_link')
                    ->label('')
                    ->tooltip('Открыть Zoom / Meet')
                    ->icon('heroicon-o-video-camera')
                    ->color('info')
                    ->url(fn (Schedule $r): ?string => $r->link)
                    ->openUrlInNewTab()
                    ->visible(fn (Schedule $r): bool => ! empty($r->link)),

                // Запись вебинара (прилетела Zoom-вебхуком recording.completed).
                Tables\Actions\Action::make('open_recording')
                    ->label('')
                    ->tooltip('Открыть запись вебинара')
                    ->icon('heroicon-o-film')
                    ->color('gray')
                    ->url(fn (Schedule $r): ?string => $r->zoom_recording_url)
                    ->openUrlInNewTab()
                    ->visible(fn (Schedule $r): bool => ! empty($r->zoom_recording_url)),

                // Посещаемость занятия: ожидаемый ростер со статусами (пришёл /
                // перешёл по ссылке / не был) + неопознанные гости Zoom.
                Tables\Actions\Action::make('attendance')
                    ->label('')
                    ->tooltip('Посещаемость')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('success')
                    ->modalHeading(fn (Schedule $r): string => 'Посещаемость — '.$r->title)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть')
                    ->modalWidth('3xl')
                    ->visible(fn (Schedule $r): bool => $r->group_id !== null || $r->course_id !== null)
                    ->modalContent(fn (Schedule $r) => view('filament.schedule.attendance', [
                        'schedule' => $r,
                        'data' => app(ClassAttendanceService::class)->forSchedule($r),
                    ])),

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),

                    // Предпросмотр карточки @samskrte_bot себе в личку.
                    Tables\Actions\Action::make('preview_bot_card')
                        ->label('Карточка в бот: предпросмотр')
                        ->icon('heroicon-o-paper-airplane')
                        ->visible(fn (Schedule $r): bool => $r->bot_card_enabled && RoleGate::any(Roles::ADMIN))
                        ->action(function (Schedule $record): void {
                            $chatId = (string) (auth()->user()?->telegram_id
                                ?: explode(',', (string) config('services.telegram.admin_id'))[0]);
                            $outcome = $chatId === ''
                                ? OpenLessonCardSender::FAILED
                                : app(OpenLessonCardSender::class)->sendCard($record, trim($chatId), OpenLessonCardBuilder::STAGE_MANUAL);

                            $outcome === OpenLessonCardSender::SENT
                                ? Notification::make()->title('Карточка отправлена вам в @samskrte_bot')->success()->send()
                                : Notification::make()
                                    ->title('Не отправилась')
                                    ->body('Нажмите /start в @samskrte_bot с аккаунта, привязанного к кабинету, и повторите.')
                                    ->danger()
                                    ->send();
                        }),

                    // Перенос: только это занятие, остальные не трогаем.
                    Tables\Actions\Action::make('reschedule')
                        ->label('Перенести')
                        ->icon('heroicon-o-arrow-right-circle')
                        ->form([
                            Forms\Components\DateTimePicker::make('start')
                                ->label('Новое начало')
                                ->seconds(false)
                                ->required()
                                ->default(fn (Schedule $r) => $r->start)
                                ->helperText('Остальные занятия группы не изменятся. Длительность сохранится.'),
                        ])
                        ->action(function (Schedule $record, array $data): void {
                            app(ScheduleMover::class)->reschedule(
                                $record,
                                Carbon::parse($data['start']),
                            );

                            Notification::make()
                                ->title('Занятие перенесено')
                                ->body($record->fresh()->start?->format('d.m.Y H:i') ?? '')
                                ->success()
                                ->send();
                        }),

                    // Отмена: this + subsequent той же группы → +7 дней.
                    Tables\Actions\Action::make('cancel_shift_week')
                        ->label('Отменить (+неделя цепочки)')
                        ->icon('heroicon-o-calendar-days')
                        ->color('warning')
                        ->visible(fn (Schedule $r): bool => $r->group_id !== null
                            && $r->start !== null
                            && $r->start->gte(now()->startOfDay()))
                        ->requiresConfirmation()
                        ->modalHeading('Отменить занятие и сдвинуть цепочку')
                        ->modalDescription(function (Schedule $r): string {
                            $n = app(ScheduleMover::class)->countChain($r);
                            $group = $r->group?->name ?? 'группа';
                            $date = $r->start?->format('d.m.Y H:i') ?? '—';

                            return "Будет сдвинуто {$n} занят. группы «{$group}» на +7 дней, "
                                ."начиная с {$date}. Слот {$date} освободится. "
                                .'При необходимости после сдвига выгрузите расписание в Google Таблицу.';
                        })
                        ->modalSubmitActionLabel('Сдвинуть на неделю')
                        ->action(function (Schedule $record): void {
                            $n = app(ScheduleMover::class)->cancelAndShiftWeek($record);

                            Notification::make()
                                ->title('Цепочка сдвинута на +1 неделю')
                                ->body("Сдвинуто занятий: {$n}. При необходимости выгрузите в Google Таблицу.")
                                ->success()
                                ->send();
                        }),

                    // Оплата блока — ручное напоминание в чат группы («до» = следующее занятие).
                    RemindPaymentInChatAction::make(
                        fn (Schedule $r) => $r->group,
                        fn (Schedule $r) => $r->end ?? $r->start ?? now(),
                    ),

                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Удалить выбранные'),
                ]),
            ])
            ->emptyStateHeading('Событий нет')
            ->emptyStateDescription('Создайте первое событие через кнопку «+ Создать» в правом верхнем углу.')
            ->emptyStateIcon('heroicon-o-calendar');
    }

    private static function humanizeDateHeader(?Carbon $dt): string
    {
        if ($dt === null) {
            return '—';
        }

        $base = $dt->translatedFormat('D, d F');

        if ($dt->isToday()) {
            return 'Сегодня · '.$base;
        }
        if ($dt->isTomorrow()) {
            return 'Завтра · '.$base;
        }
        if ($dt->isYesterday()) {
            return 'Вчера · '.$base;
        }

        return $base;
    }

    /**
     * LIVE / Скоро / Сегодня / Прошло / null.
     */
    private static function timeBadgeLabel(Schedule $r): ?string
    {
        if ($r->isLive()) {
            return 'LIVE';
        }

        $start = $r->start;
        if ($start === null) {
            return null;
        }

        $now = now();

        if ($start->isPast()) {
            return 'прошло';
        }

        $minutes = $now->diffInMinutes($start, false);
        if ($minutes <= 60) {
            return 'скоро';
        }

        return null;
    }

    private static function timeBadgeColor(Schedule $r): string
    {
        if ($r->isLive()) {
            return 'danger';
        }
        if ($r->start && $r->start->isPast()) {
            return 'gray';
        }

        return 'primary';
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSchedules::route('/'),
            'create' => Pages\CreateSchedule::route('/create'),
            'edit' => Pages\EditSchedule::route('/{record}/edit'),
        ];
    }
}
