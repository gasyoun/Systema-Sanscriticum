<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScheduleResource\Pages;
use App\Models\Course;
use App\Models\Schedule;
use App\Services\ClassAttendanceService;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
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
                    Tables\Actions\ReplicateAction::make('duplicate_next_day')
                        ->label('Дублировать на завтра')
                        ->icon('heroicon-o-arrow-uturn-right')
                        // attendances_count — виртуальный атрибут от ->counts('attendances')
                        // в запросе списка; это не колонка таблицы, иначе INSERT падает.
                        ->excludeAttributes(['attendances_count'])
                        ->beforeReplicaSaved(function (Schedule $replica, Schedule $original): void {
                            $replica->start = $original->start?->copy()->addDay();
                            $replica->end = $original->end?->copy()->addDay();
                            self::resetReplicaLifecycle($replica);
                        }),
                    Tables\Actions\ReplicateAction::make('duplicate_next_week')
                        ->label('Дублировать на +неделю')
                        ->icon('heroicon-o-calendar-days')
                        ->excludeAttributes(['attendances_count'])
                        ->beforeReplicaSaved(function (Schedule $replica, Schedule $original): void {
                            $replica->start = $original->start?->copy()->addWeek();
                            $replica->end = $original->end?->copy()->addWeek();
                            self::resetReplicaLifecycle($replica);
                        }),
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

    /**
     * «Сегодня, ср 21 мая» / «Завтра, чт 22 мая» / «пт 23 мая».
     */
    /**
     * Сбрасывает у копии занятия метки жизненного цикла и per-occurrence поля
     * Zoom. При replicate модельный хук updating() не срабатывает (это insert),
     * поэтому иначе копия унаследовала бы «уже напомнили / ссылка отправлена» и
     * запись/UUID чужой Zoom-встречи, привязанной к оригинальному занятию.
     */
    private static function resetReplicaLifecycle(Schedule $replica): void
    {
        $replica->reminded_at = null;
        $replica->absent_notified_at = null;
        $replica->group_link_posted_at = null;
        $replica->zoom_occurrence_uuid = null;
        $replica->zoom_recording_url = null;
        $replica->zoom_recording_received_at = null;
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
