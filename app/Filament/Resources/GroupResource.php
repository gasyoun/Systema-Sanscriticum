<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\GroupResource\Pages;
use App\Models\Group;
use Filament\Forms;
use Filament\Forms\Form;
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

                Forms\Components\Section::make('Набор группы')
                    ->description('H162: статус набора и уведомление студентов/кураторов о недоборе перед стартом.')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options([
                                Group::STATUS_FORMING => 'Идёт набор',
                                Group::STATUS_ACTIVE => 'Набрана / идёт обучение',
                                Group::STATUS_ARCHIVED => 'Архив',
                            ])
                            ->default(Group::STATUS_FORMING)
                            ->required(),

                        Forms\Components\TextInput::make('min_size')
                            ->label('Минимальный размер (порог «набрана»)')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Пусто — размер не проверяем, статус меняется только вручную.'),

                        Forms\Components\DatePicker::make('planned_start_date')
                            ->label('Плановая дата старта'),

                        Forms\Components\DatePicker::make('start_date_override')
                            ->label('Перенос даты старта')
                            ->helperText('Приоритет над плановой датой; перезапускает отсчёт «за N дней до старта» и шлёт немедленное уведомление о переносе.'),
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

                Tables\Columns\TextColumn::make('status')
                    ->label('Набор')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Group::STATUS_FORMING => 'Идёт набор',
                        Group::STATUS_ACTIVE => 'Набрана',
                        Group::STATUS_ARCHIVED => 'Архив',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Group::STATUS_FORMING => 'warning',
                        Group::STATUS_ACTIVE => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('min_size')
                    ->label('Порог набора')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('planned_start_date')
                    ->label('Старт (план/перенос)')
                    ->state(fn (Group $record) => $record->effectiveStartDate()?->format('d.m.Y') ?? '—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус набора')
                    ->options([
                        Group::STATUS_FORMING => 'Идёт набор',
                        Group::STATUS_ACTIVE => 'Набрана',
                        Group::STATUS_ARCHIVED => 'Архив',
                    ]),
            ])
            ->actions([
                // Перенос/фиксация плановой даты старта — приоритет над planned_start_date,
                // перезапускает отсчёт «за N дней» и шлёт немедленное уведомление о переносе.
                Tables\Actions\Action::make('fix_start_date')
                    ->label('Зафиксировать дату')
                    ->icon('heroicon-o-calendar-days')
                    ->color('warning')
                    ->visible(fn (Group $record): bool => $record->status === Group::STATUS_FORMING)
                    ->form([
                        Forms\Components\DatePicker::make('start_date_override')
                            ->label('Новая дата старта')
                            ->required()
                            ->default(fn (Group $record) => $record->effectiveStartDate()),
                    ])
                    ->action(function (Group $record, array $data): void {
                        $record->update(['start_date_override' => $data['start_date_override']]);

                        // Немедленное уведомление о переносе — не ждём следующего lead-window.
                        app(\App\Services\CuratorNotifier::class)->groupUnderEnrolled($record);
                        foreach ($record->activeUsers()->get() as $user) {
                            if ($user->telegram_id || $user->vk_id) {
                                \App\Jobs\SendMessengerAlerts::dispatch(
                                    $user,
                                    "📅 <b>Дата старта уточнена</b>\n\nНовая дата старта группы «{$record->name}»: <b>{$record->effectiveStartDate()->format('d.m.Y')}</b>."
                                );
                            }
                        }
                    }),
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
                        'data' => app(\App\Services\ClassAttendanceService::class)
                            ->forGroup($record, now()->subWeeks(8), now()),
                    ])),
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
