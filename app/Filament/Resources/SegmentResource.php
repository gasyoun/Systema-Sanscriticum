<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SegmentResource\Pages;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Segment;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * GC-A1 segment engine (H1637). Entirely gated on the `marketing_segments`
 * flag (in addition to the admin-only role gate) — same pattern as
 * CampaignResource: with the flag OFF this resource must not appear at all.
 * Built-in segments (`is_builtin=true`, seeded by SegmentSeeder) keep their
 * name/description editable but their filter is fixed to the wrapped report
 * — the criteria builder is only shown for custom segments.
 */
class SegmentResource extends Resource
{
    protected static ?string $model = Segment::class;

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?int $navigationSort = 122;

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Сегменты';

    protected static ?string $modelLabel = 'Сегмент';

    protected static ?string $pluralModelLabel = 'Сегменты';

    private static function isFlagEnabled(): bool
    {
        return (bool) config('features.marketing_segments');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::isFlagEnabled() && RoleGate::adminOnly();
    }

    public static function canViewAny(): bool
    {
        return static::isFlagEnabled() && RoleGate::adminOnly();
    }

    public static function canCreate(): bool
    {
        return static::isFlagEnabled() && RoleGate::adminOnly();
    }

    public static function canEdit($record): bool
    {
        return static::isFlagEnabled() && RoleGate::adminOnly();
    }

    public static function canDelete($record): bool
    {
        // Built-in segments are seeded infrastructure, not editorial content —
        // deleting one would silently break the report it wraps.
        return static::isFlagEnabled() && RoleGate::adminOnly() && ! $record->is_builtin;
    }

    public static function canDeleteAny(): bool
    {
        return static::isFlagEnabled() && RoleGate::adminOnly();
    }

    private static function criterionTypeOptions(): array
    {
        return [
            'group' => 'Состоит в группе',
            'last_activity_days' => 'Неактивен N+ дней',
            'completed_lesson' => 'Пройден урок',
            'tariff_owned' => 'Владеет тарифом (оплатил курс/блок)',
            'attendance_below' => 'Посещаемость ниже порога',
            'lead_status' => 'Статус лида (по email)',
            'debtor' => 'Должник',
            'utm_source' => 'UTM-источник',
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Сегмент')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Название')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Textarea::make('description')
                        ->label('Описание')
                        ->rows(2)
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('builtin_notice')
                        ->label('Встроенный сегмент')
                        ->content('Фильтр этого сегмента фиксирован — он обёртка над существующим отчётом и правится только через код (ReactivationReport/DebtorsReport/StuckStudentsReport).')
                        ->visible(fn (?Segment $record): bool => (bool) $record?->is_builtin)
                        ->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make('Условия фильтра')
                ->description('Все условия объединяются через И (AND) — совпадают студенты, удовлетворяющие ВСЕМ строкам ниже.')
                ->schema([
                    Forms\Components\Repeater::make('criteria')
                        ->label('Условия')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\Select::make('type')
                                ->label('Тип условия')
                                ->options(self::criterionTypeOptions())
                                ->required()
                                ->live(),

                            Forms\Components\Select::make('group_id')
                                ->label('Группа')
                                ->options(fn () => Group::query()->pluck('name', 'id'))
                                ->searchable()
                                ->visible(fn (Get $get): bool => $get('type') === 'group'),

                            Forms\Components\TextInput::make('min_days')
                                ->label('Минимум дней неактивности')
                                ->numeric()
                                ->minValue(1)
                                ->visible(fn (Get $get): bool => $get('type') === 'last_activity_days'),

                            Forms\Components\Select::make('lesson_id')
                                ->label('Урок')
                                ->options(fn () => Lesson::query()->limit(200)->pluck('title', 'id'))
                                ->searchable()
                                ->visible(fn (Get $get): bool => $get('type') === 'completed_lesson'),

                            Forms\Components\TextInput::make('course_id')
                                ->label('ID курса')
                                ->numeric()
                                ->visible(fn (Get $get): bool => in_array($get('type'), ['tariff_owned', 'attendance_below'], true)),

                            Forms\Components\TextInput::make('tariff')
                                ->label('Тариф (например full, block_1)')
                                ->visible(fn (Get $get): bool => $get('type') === 'tariff_owned'),

                            Forms\Components\TextInput::make('rate')
                                ->label('Порог посещаемости (0..1)')
                                ->numeric()
                                ->step(0.01)
                                ->minValue(0)
                                ->maxValue(1)
                                ->visible(fn (Get $get): bool => $get('type') === 'attendance_below'),

                            Forms\Components\TextInput::make('status')
                                ->label('Статус лида (ключ)')
                                ->visible(fn (Get $get): bool => $get('type') === 'lead_status'),

                            Forms\Components\TextInput::make('value')
                                ->label('Значение utm_source')
                                ->visible(fn (Get $get): bool => $get('type') === 'utm_source'),
                        ])
                        ->columns(3)
                        ->addActionLabel('Добавить условие')
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => self::criterionTypeOptions()[$state['type'] ?? ''] ?? 'Условие')
                        ->columnSpanFull(),
                ])
                ->visible(fn (?Segment $record): bool => ! ($record?->is_builtin ?? false))
                ->columnSpanFull(),

            Forms\Components\Hidden::make('is_builtin')->default(false),
            Forms\Components\Hidden::make('created_by')->default(fn () => auth()->id()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->description(fn (Segment $r): ?string => $r->description),

                Tables\Columns\IconColumn::make('is_builtin')
                    ->label('Встроенный')
                    ->boolean(),

                Tables\Columns\TextColumn::make('builtin_key')
                    ->label('Ключ')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('criteria')
                    ->label('Условий')
                    ->state(fn (Segment $r): int => is_array($r->criteria) ? count($r->criteria) : 0),

                Tables\Columns\TextColumn::make('matched_count')
                    ->label('Совпадений')
                    ->state(fn (Segment $r): int => $r->matchingStudentsQuery()->count())
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Автор')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_builtin')
                    ->label('Встроенный')
                    ->placeholder('Все')
                    ->trueLabel('Только встроенные')
                    ->falseLabel('Только пользовательские'),
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
            'index' => Pages\ListSegments::route('/'),
            'create' => Pages\CreateSegment::route('/create'),
            'edit' => Pages\EditSegment::route('/{record}/edit'),
        ];
    }
}
