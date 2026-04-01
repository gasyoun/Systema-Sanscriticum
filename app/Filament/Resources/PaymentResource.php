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
                Forms\Components\Section::make('Детали транзакции')->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('Студент')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\Select::make('course_id')
                        ->label('Курс')
                        ->relationship('course', 'title')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\Select::make('tariff')
                        ->label('Тариф (Доступ)')
                        ->options(function () {
                            $options = ['full' => 'Весь курс целиком'];
                            for ($i = 1; $i <= 100; $i++) {
                                $startLesson = ($i - 1) * 4 + 1;
                                $endLesson = $i * 4;
                                $options["block_{$i}"] = "Блок {$i} (Занятия {$startLesson}-{$endLesson})";
                            }
                            return $options;
                        })
                        ->searchable()
                        ->default('full')
                        ->required()
                        ->live() 
                        ->afterStateUpdated(function ($state, callable $set) {
                            if ($state === 'full') {
                                $set('start_block', null);
                                $set('end_block', null);
                            } elseif (str_starts_with($state ?? '', 'block_')) {
                                $blockNum = (int) str_replace('block_', '', $state);
                                $set('start_block', $blockNum);
                                $set('end_block', $blockNum);
                            }
                        })
                        ->columnSpanFull(),
                ])->columns(2),

                Forms\Components\Section::make('Финансы')->schema([
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

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options([
                                'pending' => 'Ожидает оплаты',
                                'paid' => 'Оплачено',
                                'canceled' => 'Отменено / Ошибка',
                            ])
                            ->default('paid')
                            ->required(),

                        Forms\Components\TextInput::make('transaction_id')
                            ->label('ID транзакции (Банк / Расход)')
                            ->maxLength(255),
                    ]),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // 1. КОМПАКТНАЯ ДАТА (без времени)
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->date('d.m.Y') // <-- Убрали время, оставили только компактную дату
                    ->sortable()
                    ->color('gray')
                    ->size('sm'),

                // 2. СТУДЕНТ (Добавили wrap, чтобы сузить колонку)
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Студент')
                    ->searchable()
                    ->sortable()
                    ->wrap() // <-- МАГИЯ ЗДЕСЬ: Длинные ФИО перенесутся на новую строку
                    ->weight(\Filament\Support\Enums\FontWeight::Bold)
                    ->description(fn (Payment $record): string => $record->user->email ?? 'Нет email'),

                // 3. КУРС (Займет всё освободившееся пространство)
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс и Тариф')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(function (Payment $record) {
                        $start = (int)$record->start_block;
                        $end = (int)$record->end_block;
                        
                        if ($start > 0) {
                            if ($end <= 0 || $start === $end) return "Блок {$start}";
                            return "Блоки {$start} - {$end}";
                        }
                        
                        if ($record->tariff === 'Расход') return 'Технический расход';
                        return 'Весь курс';
                    }),

                // 4. СУММА
                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('RUB', locale: 'ru')
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::ExtraBold)
                    ->color(fn (Payment $record) => $record->amount < 0 ? 'danger' : ($record->status === 'paid' ? 'success' : 'gray'))
                    ->alignment(\Filament\Support\Enums\Alignment::End),

                // 5. СТАТУС
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'paid', 'success' => 'success',     
                        'pending' => 'warning',  
                        'canceled' => 'danger',  
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'paid', 'success' => 'Оплачено',
                        'pending' => 'Ожидает',
                        'canceled' => 'Отменено',
                        default => $state ?? 'Не указан',
                    })
                    ->alignment(\Filament\Support\Enums\Alignment::Center),

                // 6. ПРИМЕЧАНИЕ
                Tables\Columns\TextColumn::make('transaction_id')
                    ->label('Примечание (Банк)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(30)
                    ->color('gray'),
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
                Tables\Actions\EditAction::make()->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped(); 
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