<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CourseWaitlistItemResource\Pages;
use App\Models\CourseWaitlistItem;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * «Список ожидания» (MG ruling 31-08-2026): курсы-кандидаты, за которые голосуют
 * ученики из кабинета. Порог голосов = min_payers (default 8) → оплата; при
 * нужном числе оплат старт, иначе лестница переносов (октябрь→январь/март→июль→
 * сентябрь след. года, максимум 4 попытки × 4 года).
 */
class CourseWaitlistItemResource extends Resource
{
    protected static ?string $model = CourseWaitlistItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?int $navigationSort = 72;

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?string $navigationLabel = 'Список ожидания';

    protected static ?string $modelLabel = 'Кандидат списка ожидания';

    protected static ?string $pluralModelLabel = 'Список ожидания';

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
        return RoleGate::adminOnly();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Кандидат')
                    ->schema([
                        Forms\Components\TextInput::make('course_title')
                            ->label('Название курса')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('slug')
                            ->label('Публичный слаг')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(180)
                            ->rules(['regex:/^[a-z0-9-]+$/'])
                            ->helperText('Для фида/виджета; латиница, дефисы. Наружу id не отдаём.')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('teacher_name')
                            ->label('Преподаватель')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('slot')
                            ->label('Слот')
                            ->placeholder('пн 18:00, сб 13:00…')
                            ->maxLength(40),

                        Forms\Components\Select::make('course_id')
                            ->label('Привязанный курс (когда создан)')
                            ->relationship('course', 'title')
                            ->searchable()
                            ->preload()
                            ->nullable(),

                        Forms\Components\DatePicker::make('earliest_start_at')
                            ->label('Не раньше')
                            ->native(false),

                        Forms\Components\TextInput::make('min_payers')
                            ->label('Минимум оплат')
                            ->numeric()
                            ->default(8)
                            ->minValue(1)
                            ->maxValue(60),

                        Forms\Components\TextInput::make('block_price_rub')
                            ->label('Цена блока, ₽')
                            ->numeric()
                            ->minValue(0),

                        Forms\Components\Select::make('kind')
                            ->label('Тип (для лестницы переносов)')
                            ->options(CourseWaitlistItem::KINDS)
                            ->default('other')
                            ->required(),

                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options(CourseWaitlistItem::STATUSES)
                            ->default('collecting')
                            ->required(),

                        Forms\Components\TextInput::make('start_attempts')
                            ->label('Попыток старта')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(16)
                            ->default(0),
                    ])->columns(2),

                Forms\Components\Section::make('История (для прогноза)')
                    ->schema([
                        Forms\Components\TextInput::make('historical_paid_n')
                            ->label('Оплат в прошлом потоке')
                            ->numeric()
                            ->minValue(0),

                        Forms\Components\Textarea::make('historical_notes')
                            ->label('История потоков')
                            ->rows(2)
                            ->placeholder('1 поток 2025 — 152 ученика, 2 поток 2026 — 90')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Витрина')
                    ->schema([
                        Forms\Components\Toggle::make('is_listed')
                            ->label('Показывать в списке ожидания')
                            ->default(true),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Порядок')
                            ->numeric()
                            ->default(0),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('course_title')
                    ->label('Курс')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('teacher_name')
                    ->label('Преподаватель')
                    ->searchable(),

                Tables\Columns\TextColumn::make('slot')
                    ->label('Слот')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('earliest_start_at')
                    ->label('Не раньше')
                    ->date('d.m.Y')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('votes_count')
                    ->label('Голоса')
                    ->counts('votes')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('min_payers')
                    ->label('Мин.'),

                Tables\Columns\TextColumn::make('block_price_rub')
                    ->label('Цена блока')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 0, ',', ' ').' ₽'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CourseWaitlistItem::STATUSES[$state] ?? ($state ?? '—'))
                    ->color(fn (?string $state): string => match ($state) {
                        'collecting' => 'gray',
                        'payment_open' => 'success',
                        'payment_deadline_passed' => 'danger',
                        'postponed' => 'warning',
                        'scheduled' => 'info',
                        'closed' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_attempts')
                    ->label('Попыток'),

                Tables\Columns\TextColumn::make('forecast')
                    ->label('Прогноз оплат')
                    ->getStateUsing(fn (CourseWaitlistItem $record): string => sprintf('%d–%d', ...array_values($record->forecastPayments()))),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(CourseWaitlistItem::STATUSES),
                Tables\Filters\SelectFilter::make('kind')
                    ->label('Тип')
                    ->options(CourseWaitlistItem::KINDS),
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
            'index' => Pages\ListCourseWaitlistItems::route('/'),
            'create' => Pages\CreateCourseWaitlistItem::route('/create'),
            'edit' => Pages\EditCourseWaitlistItem::route('/{record}/edit'),
        ];
    }
}
