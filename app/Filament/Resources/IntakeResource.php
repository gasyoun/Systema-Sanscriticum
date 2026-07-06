<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntakeResource\Pages;
use App\Models\Intake;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * «Набор» — плановая волна набора студентов (Июль 2026, Сентябрь 2026, …).
 * Существует ДО формирования групп: держит известные номера будущих групп и
 * после формирования порождает реальные Group (groups.intake_id). Верхний
 * уровень доски набора — заявки (WaitlistEntry) группируются по этому Intake.
 */
class IntakeResource extends Resource
{
    protected static ?string $model = Intake::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 71;

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?string $navigationLabel = 'Наборы';

    protected static ?string $modelLabel = 'Набор';

    protected static ?string $pluralModelLabel = 'Наборы';

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
                Forms\Components\Section::make('Набор')
                    ->schema([
                        Forms\Components\TextInput::make('label')
                            ->label('Название')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Июль 2026')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('season')
                            ->label('Сезон / месяц словом')
                            ->maxLength(255)
                            ->placeholder('Июль, осень…'),

                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options(Intake::STATUSES)
                            ->default('planning')
                            ->required(),

                        Forms\Components\TextInput::make('target_year')
                            ->label('Год')
                            ->numeric()
                            ->required()
                            ->minValue(2020)
                            ->maxValue(2100),

                        Forms\Components\Select::make('target_month')
                            ->label('Месяц')
                            ->options([
                                1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
                                5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
                                9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
                            ])
                            ->placeholder('— нечёткий («осень», «на 2027»)'),

                        Forms\Components\DatePicker::make('opens_at')
                            ->label('Ориентировочный старт набора')
                            ->native(false),

                        Forms\Components\TagsInput::make('planned_group_numbers')
                            ->label('Известные номера будущих групп')
                            ->placeholder('61')
                            ->helperText('Номера групп, которые известны до их создания.')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('notes')
                            ->label('Заметки')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Intake::STATUSES[$state] ?? ($state ?? '—'))
                    ->color(fn (?string $state): string => match ($state) {
                        'planning' => 'gray',
                        'forming' => 'warning',
                        'open' => 'success',
                        'closed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('target_year')
                    ->label('Год')
                    ->sortable(),

                Tables\Columns\TextColumn::make('waitlist_entries_count')
                    ->label('Заявок')
                    ->counts('waitlistEntries')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('groups_count')
                    ->label('Групп')
                    ->counts('groups')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('opens_at')
                    ->label('Старт')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('target_year', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(Intake::STATUSES),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIntakes::route('/'),
            'create' => Pages\CreateIntake::route('/create'),
            'edit' => Pages\EditIntake::route('/{record}/edit'),
        ];
    }
}
