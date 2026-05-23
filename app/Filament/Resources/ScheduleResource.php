<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ScheduleResource\Pages;
use App\Models\Schedule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ScheduleResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Schedule::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar'; // <-- Иконка календаря

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?string $navigationLabel = 'Расписание';

    protected static ?string $pluralModelLabel = 'Расписание';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
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
                        Forms\Components\Select::make('group_id')
                            ->relationship('group', 'name')
                            ->label('Для группы (Пусто = для всех)')
                            ->searchable()
                            ->preload(),

                        Forms\Components\ColorPicker::make('color')
                            ->label('Цвет метки')
                            ->default('#3788d8'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start', 'asc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->striped()
            ->groups([
                Tables\Grouping\Group::make('start')
                    ->label('Дата')
                    ->date()
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(fn (Schedule $r): string => self::humanizeDateHeader($r->start))
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
                        ? \Illuminate\Support\Str::limit($r->description, 120)
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

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\ReplicateAction::make('duplicate_next_day')
                        ->label('Дублировать на завтра')
                        ->icon('heroicon-o-arrow-uturn-right')
                        ->beforeReplicaSaved(function (Schedule $replica, Schedule $original): void {
                            $replica->start = $original->start?->copy()->addDay();
                            $replica->end = $original->end?->copy()->addDay();
                        }),
                    Tables\Actions\ReplicateAction::make('duplicate_next_week')
                        ->label('Дублировать на +неделю')
                        ->icon('heroicon-o-calendar-days')
                        ->beforeReplicaSaved(function (Schedule $replica, Schedule $original): void {
                            $replica->start = $original->start?->copy()->addWeek();
                            $replica->end = $original->end?->copy()->addWeek();
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
    private static function humanizeDateHeader(?\Illuminate\Support\Carbon $dt): string
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
