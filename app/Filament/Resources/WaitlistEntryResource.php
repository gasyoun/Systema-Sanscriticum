<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WaitlistEntryResource\Pages;
use App\Models\Intake;
use App\Models\PaymentPromise;
use App\Models\WaitlistEntry;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Grouping\Group as TableGroup;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Доска набора: строки «таблицы набора» (заявки), сгруппированные по Intake.
 * Для возвратных студентов история групп, блок отвала и платёжная надёжность
 * ВЫВОДЯТСЯ из связанного User (методы модели), а не хранятся копией — поэтому
 * соответствующие колонки/фильтры считаются через связь student.
 */
class WaitlistEntryResource extends Resource
{
    protected static ?string $model = WaitlistEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 72;

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?string $navigationLabel = 'Лист набора';

    protected static ?string $modelLabel = 'Заявка в набор';

    protected static ?string $pluralModelLabel = 'Лист набора';

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canEdit($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canCreate(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function canDelete($record): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Абитуриент')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Имя')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('telegram_username')
                            ->label('Никнейм в тукане (Telegram)')
                            ->placeholder('@username')
                            ->helperText('Ключ матчинга возвратного студента — @ и t.me/ убираются автоматически.')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('contact')
                            ->label('Иной контакт')
                            ->placeholder('телефон / e-mail')
                            ->maxLength(255),

                        Forms\Components\Select::make('intake_id')
                            ->label('Набор')
                            ->relationship('intake', 'label')
                            ->searchable()
                            ->preload()
                            ->placeholder('— пока без набора'),

                        Forms\Components\Select::make('level')
                            ->label('Уровень')
                            ->options(WaitlistEntry::LEVELS)
                            ->placeholder('—'),

                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options(WaitlistEntry::STATUSES)
                            ->default('waiting')
                            ->required(),

                        Forms\Components\TextInput::make('preferred_schedule')
                            ->label('Время и дни')
                            ->placeholder('Суббота 9:00')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('timezone_note')
                            ->label('Часовой пояс')
                            ->placeholder('+6 МСК')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('prior_group_note')
                            ->label('Прежняя группа (свободный текст)')
                            ->helperText('Только для НЕсматченных с аккаунтом — у сматченных история берётся из связей.')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('last_outreach_at')
                                ->label('Последняя рассылка')
                                ->native(false),
                            Forms\Components\DatePicker::make('last_reply_at')
                                ->label('Последний ответ')
                                ->native(false),
                        ]),

                        Forms\Components\Textarea::make('notes')
                            ->label('Примечания')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('student.groups', 'student.paymentPromises'))
            ->groups([
                TableGroup::make('intake.label')
                    ->label('Набор')
                    ->getTitleFromRecordUsing(fn (WaitlistEntry $r): string => $r->intake?->label ?? 'Без набора'),
            ])
            ->defaultGroup('intake.label')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->description(fn (WaitlistEntry $r): ?string => $r->telegram_username ? '@'.$r->telegram_username : null),

                Tables\Columns\IconColumn::make('user_id')
                    ->label('Возвратный')
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-path')
                    ->falseIcon('heroicon-o-sparkles')
                    ->trueColor('info')
                    ->falseColor('gray')
                    ->tooltip(fn (WaitlistEntry $r): string => $r->isReturning() ? 'Возвратный студент (сматчен)' : 'Новичок без аккаунта'),

                Tables\Columns\TextColumn::make('reliability')
                    ->label('Надёжность')
                    ->badge()
                    ->state(fn (WaitlistEntry $r): string => $r->reliabilityBadge()['label'])
                    ->color(fn (WaitlistEntry $r): string => $r->reliabilityBadge()['color']),

                Tables\Columns\TextColumn::make('drop_block')
                    ->label('Отвал после блока')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—')
                    ->state(fn (WaitlistEntry $r): ?string => $r->lastDropBlock() !== null ? 'блок '.$r->lastDropBlock() : null),

                Tables\Columns\TextColumn::make('prior_groups')
                    ->label('Прежние группы')
                    ->placeholder('—')
                    ->state(fn (WaitlistEntry $r): ?string => $r->priorGroupNames()->implode(', ') ?: null)
                    ->limit(30)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('level')
                    ->label('Уровень')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => WaitlistEntry::LEVELS[$state] ?? '—')
                    ->color(fn (?string $state): string => $state === 'continuing' ? 'success' : 'gray'),

                Tables\Columns\SelectColumn::make('status')
                    ->label('Статус')
                    ->options(WaitlistEntry::STATUSES)
                    ->selectablePlaceholder(false)
                    ->width('1%'),

                Tables\Columns\TextColumn::make('preferred_schedule')
                    ->label('Время и дни')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('timezone_note')
                    ->label('Пояс')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_outreach_at')
                    ->label('Рассылка')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_reply_at')
                    ->label('Ответ')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('intake_id')
                    ->label('Набор')
                    ->relationship('intake', 'label')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(WaitlistEntry::STATUSES),

                Tables\Filters\SelectFilter::make('level')
                    ->label('Уровень')
                    ->options(WaitlistEntry::LEVELS),

                Tables\Filters\TernaryFilter::make('returning')
                    ->label('Возвратный студент')
                    ->placeholder('Все заявки')
                    ->trueLabel('Только сматченные')
                    ->falseLabel('Только новички')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('user_id'),
                        false: fn (Builder $query) => $query->whereNull('user_id'),
                        blank: fn (Builder $query) => $query,
                    ),

                // Фильтр по блоку отвала: структурный сигнал из истории
                // (course_user.left_after_block у сматченного студента).
                Tables\Filters\SelectFilter::make('drop_block')
                    ->label('Отвал после блока')
                    ->options([
                        1 => 'после блока 1',
                        2 => 'после блока 2',
                        3 => 'после блока 3',
                        4 => 'после блока 4',
                        5 => 'после блока 5',
                        6 => 'после блока 6',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('student.courses', fn (Builder $q) => $q
                            ->wherePivot('left_after_block', (int) $data['value']));
                    }),

                // Win-back / риск неоплаты: есть непогашенное обещание оплаты
                // (active/expired) у сматченного студента.
                Tables\Filters\TernaryFilter::make('payment_risk')
                    ->label('Риск неоплаты')
                    ->placeholder('Все заявки')
                    ->trueLabel('Есть непогашенное обещание')
                    ->falseLabel('Без долга по обещаниям')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('student.paymentPromises',
                            fn (Builder $q) => $q->whereIn('status', [
                                PaymentPromise::STATUS_ACTIVE,
                                PaymentPromise::STATUS_EXPIRED,
                            ])),
                        false: fn (Builder $query) => $query->whereDoesntHave('student.paymentPromises',
                            fn (Builder $q) => $q->whereIn('status', [
                                PaymentPromise::STATUS_ACTIVE,
                                PaymentPromise::STATUS_EXPIRED,
                            ])),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('set_status')
                    ->label('Статус')
                    ->icon('heroicon-o-flag')
                    ->color('gray')
                    ->form([
                        Forms\Components\Select::make('status')
                            ->label('Новый статус')
                            ->options(WaitlistEntry::STATUSES)
                            ->required(),
                    ])
                    ->action(function (WaitlistEntry $record, array $data): void {
                        $record->update(['status' => (string) $data['status']]);
                        Notification::make()->title('Статус обновлён')->success()->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('set_status')
                        ->label('Сменить статус')
                        ->icon('heroicon-o-flag')
                        ->color('gray')
                        ->form([
                            Forms\Components\Select::make('status')
                                ->label('Статус')
                                ->options(WaitlistEntry::STATUSES)
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each->update(['status' => (string) $data['status']]);
                            Notification::make()->title('Обновлено: '.$records->count())->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('assign_intake')
                        ->label('Привязать к набору')
                        ->icon('heroicon-o-calendar-days')
                        ->color('gray')
                        ->form([
                            Forms\Components\Select::make('intake_id')
                                ->label('Набор')
                                ->options(fn () => Intake::query()->orderByDesc('target_year')->pluck('label', 'id'))
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each->update(['intake_id' => (int) $data['intake_id']]);
                            Notification::make()->title('Привязано: '.$records->count())->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWaitlistEntries::route('/'),
            'create' => Pages\CreateWaitlistEntry::route('/create'),
            'edit' => Pages\EditWaitlistEntry::route('/{record}/edit'),
        ];
    }
}
