<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TariffResource\Pages;
use App\Models\Tariff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TariffResource extends Resource
{
    protected static ?string $model = Tariff::class;

    // Иконка ценника для меню
    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?int $navigationSort = 100;
    protected static ?string $navigationGroup = 'Продажи';
    protected static ?string $navigationLabel = 'Тарифы (Цены)';
    protected static ?string $modelLabel = 'Тариф';
    protected static ?string $pluralModelLabel = 'Тарифы';
    
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основная информация')
                    ->schema([
                        Forms\Components\Select::make('course_id')
                            ->relationship('course', 'title')
                            ->label('Курс')
                            ->searchable()
                            ->preload()
                            ->helperText('К какому курсу относится этот тариф (оставьте пустым, если это пакет из нескольких курсов)'),

                        Forms\Components\TextInput::make('title')
                            ->label('Название тарифа')
                            ->required()
                            ->placeholder('Например: Блок 1 (Занятия 1-4) или Весь курс целиком'),

                        Forms\Components\Select::make('type')
                            ->label('Тип тарифа')
                            ->options([
                                'full' => 'Весь курс целиком',
                                'block' => 'Отдельный блок (модуль)',
                                'vip' => 'VIP (с куратором)',
                                'bundle' => 'Пакет (несколько курсов)',
                            ])
                            ->default('full')
                            ->required()
                            ->live(), // Делаем поле "живым", чтобы мгновенно реагировать на выбор

                        // Это поле появится ТОЛЬКО если выбрали тип "Отдельный блок"
                        Forms\Components\TextInput::make('block_number')
                            ->label('Номер блока')
                            ->numeric()
                            ->visible(fn (Forms\Get $get) => $get('type') === 'block')
                            ->required(fn (Forms\Get $get) => $get('type') === 'block')
                            ->helperText('Введите цифру (например: 1, 2, 3), чтобы система поняла, к каким именно урокам дать доступ после оплаты.'),

                        Forms\Components\Select::make('course_block_id')
                            ->label('Сущность блока (даты, флаг «сейчас идёт»)')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'block')
                            ->options(function (Forms\Get $get) {
                                $courseId = $get('course_id');
                                if (!$courseId) return [];
                                return \App\Models\CourseBlock::where('course_id', $courseId)
                                    ->orderBy('number')
                                    ->get()
                                    ->mapWithKeys(fn ($b) => [$b->id => '№'.$b->number.($b->title ? ' — '.$b->title : '')])
                                    ->all();
                            })
                            ->searchable()
                            ->helperText('Привяжите тариф к блоку курса, чтобы система знала даты блока и его актуальность. Создать блок можно во вкладке «Блоки курса» внутри витрины.'),
                    ])->columns(2),

                Forms\Components\Section::make('Цены и отображение')
                    ->schema([
                        Forms\Components\TextInput::make('price')
                            ->label('Актуальная цена к оплате (₽)')
                            ->numeric()
                            ->required(),

                        Forms\Components\TextInput::make('old_price')
                            ->label('Старая (зачеркнутая) цена (₽)')
                            ->numeric()
                            ->helperText('Необязательно. Для визуальной скидки на лендинге.'),

                        Forms\Components\Textarea::make('description')
                            ->label('Описание (что входит в тариф)')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Можно перечислить плюсы тарифа, они выведутся на витрине.'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Активен (можно купить)')
                            ->default(true),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->sortable()
                    ->searchable()
                    ->placeholder('Пакет курсов'),

                Tables\Columns\TextColumn::make('title')
                    ->label('Название')
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'full' => 'success',
                        'block' => 'info',
                        'vip' => 'warning',
                        'bundle' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'full' => 'Весь курс',
                        'block' => 'Блок',
                        'vip' => 'VIP',
                        'bundle' => 'Пакет',
                        default => $state,
                    }),

                // КОЛОНКА БЛОКА ТЕПЕРЬ СТОИТ ОТДЕЛЬНО И ПРАВИЛЬНО
                Tables\Columns\TextColumn::make('block_number')
                    ->label('Блок')
                    ->formatStateUsing(fn ($state) => $state ? "Блок $state" : '—')
                    ->badge()
                    ->color('info')
                    ->sortable(),        

                Tables\Columns\TextColumn::make('price')
                    ->label('Цена')
                    ->money('RUB', locale: 'ru')
                    ->sortable(),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Активен'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('course_id')
                    ->relationship('course', 'title')
                    ->label('Фильтр по курсу'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('block_number', 'asc'); // Оставил правильную сортировку по блокам
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTariffs::route('/'),
            'create' => Pages\CreateTariff::route('/create'),
            'edit' => Pages\EditTariff::route('/{record}/edit'),
        ];
    }
}