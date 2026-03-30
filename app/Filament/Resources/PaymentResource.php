<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int $navigationSort = 80;
    protected static ?string $navigationGroup = 'Продажи';
    protected static ?string $navigationLabel = 'Финансы';
    protected static ?string $pluralModelLabel = 'Транзакции';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->label('Студент')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\Select::make('course_id')
                    ->label('Курс')
                    ->relationship('course', 'title')
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\Select::make('tariff')
                    ->label('Тариф (Доступ)')
                    ->options([
                        'full' => 'Весь курс (16 занятий)',
                        'block_1' => 'Блок 1 (Занятия 1-4)',
                        'block_2' => 'Блок 2 (Занятия 5-8)',
                        'block_3' => 'Блок 3 (Занятия 9-12)',
                        'block_4' => 'Блок 4 (Занятия 13-16)',
                    ])
                    ->default('full')
                    ->required(),

                // --- НОВЫЙ БЛОК: Сумма и Номера блоков ---
                Forms\Components\Grid::make(3)
                    ->schema([
                        Forms\Components\TextInput::make('amount')
                            ->label('Сумма (₽)')
                            ->numeric()
                            ->required(),

                        Forms\Components\TextInput::make('start_block')
                            ->label('Оплачен с блока №')
                            ->numeric()
                            ->helperText('Например: 52'),

                        Forms\Components\TextInput::make('end_block')
                            ->label('По блок №')
                            ->numeric()
                            ->helperText('Пусто, если курс куплен целиком'),
                    ]),

                Forms\Components\Select::make('status')
                    ->label('Статус')
                    ->options([
                        'pending' => 'Ожидает оплаты',
                        'paid' => 'Оплачено',
                        'canceled' => 'Отменено / Ошибка',
                    ])
                    ->default('pending')
                    ->required(),

                Forms\Components\TextInput::make('transaction_id')
                    ->label('ID транзакции (Банк)')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Студент')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Payment $record): string => $record->user->email ?? ''),

                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                // --- НОВАЯ КОЛОНКА: Умное отображение блоков ---
                Tables\Columns\TextColumn::make('blocks_range')
                    ->label('Оплаченные блоки')
                    ->state(function (Payment $record) {
                        if ($record->start_block && $record->end_block) {
                            if ($record->start_block === $record->end_block) {
                                return "Блок {$record->start_block}";
                            }
                            return "Блоки {$record->start_block} - {$record->end_block}";
                        }
                        return 'Весь курс (или не указано)';
                    })
                    ->badge()
                    ->color(fn ($state) => $state === 'Весь курс (или не указано)' ? 'success' : 'info'),

                Tables\Columns\TextColumn::make('tariff')
                    ->label('Тариф')
                    ->badge() 
                    ->color(fn (string $state): string => match ($state) {
                        'full' => 'success', 
                        default => 'info',   
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'full' => 'Весь курс',
                        'block_1' => 'Блок 1',
                        'block_2' => 'Блок 2',
                        'block_3' => 'Блок 3',
                        'block_4' => 'Блок 4',
                        default => $state,
                    })
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true), // Скрыл по умолчанию, чтобы не загромождать таблицу

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('RUB', locale: 'ru')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid', 'success' => 'success',     
                        'pending' => 'warning',  
                        'canceled' => 'danger',  
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'paid', 'success' => 'Оплачено',
                        'pending' => 'Ожидает',
                        'canceled' => 'Отменено',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('transaction_id')
                    ->label('ID Банка')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('course_id')
                    ->label('Фильтр по курсу')
                    ->relationship('course', 'title'),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Фильтр по статусу')
                    ->options([
                        'pending' => 'Ожидает оплаты',
                        'paid' => 'Оплачено',
                        'canceled' => 'Отменено',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
        ];
    }
}