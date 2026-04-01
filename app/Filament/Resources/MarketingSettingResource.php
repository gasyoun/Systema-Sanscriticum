<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketingSettingResource\Pages;
use App\Models\MarketingSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MarketingSettingResource extends Resource
{
    protected static ?string $model = MarketingSetting::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-8-tooth';
    protected static ?string $navigationGroup = 'Маркетинг';
    protected static ?string $navigationLabel = 'Глобальные настройки';
    protected static ?string $modelLabel = 'Настройки';
    protected static ?string $pluralModelLabel = 'Настройки маркетинга';

    public static function canCreate(): bool
    {
        return MarketingSetting::count() === 0;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // --- БЛОК 1: Главный рубильник ---
                Forms\Components\Section::make('Общий статус лояльности')
                    ->schema([
                        Forms\Components\Toggle::make('is_loyalty_active')
                            ->label('Программа лояльности включена')
                            ->helperText('Если выключить, никакие скидки из блоков ниже применяться не будут.')
                            ->default(false)
                            ->live(), // Делаем переключатель "живым", чтобы скрывать/показывать блоки ниже
                    ]),

                // --- БЛОК 2: Пакетные скидки (Единовременная покупка) ---
                Forms\Components\Section::make('Скидки за объем в чеке (Пакетные)')
                    ->description('Применяются при единовременной покупке нескольких факультативов (при полной оплате).')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('bundle_2_discount')
                                ->label('Скидка за 2 курса (%)')
                                ->numeric()
                                ->default(10)
                                ->minValue(0)->maxValue(100),
                                
                            Forms\Components\TextInput::make('bundle_3_discount')
                                ->label('Скидка за 3 и более курсов (%)')
                                ->numeric()
                                ->default(15)
                                ->minValue(0)->maxValue(100),
                        ]),
                    ])
                    ->visible(fn (Forms\Get $get) => $get('is_loyalty_active')), // Показываем только если лояльность включена

                // --- БЛОК 3: Оптовики (Накопительные скидки за прошлый год) ---
                Forms\Components\Section::make('Накопительные скидки ("Оптовики")')
                    ->description('Скидки на факультативы, основанные на количестве купленных курсов за прошлый календарный год. Работают даже при поблочной оплате.')
                    ->schema([
                        // Мелкий опт
                        Forms\Components\Fieldset::make('Мелкий опт')
                            ->schema([
                                Forms\Components\TextInput::make('wholesale_small_threshold')
                                    ->label('Порог курсов (от)')
                                    ->numeric()
                                    ->default(5)
                                    ->minValue(1),
                                    
                                Forms\Components\TextInput::make('wholesale_small_discount')
                                    ->label('Скидка (%)')
                                    ->numeric()
                                    ->default(10)
                                    ->minValue(0)->maxValue(100),
                            ])->columns(2),

                        // Крупный опт
                        Forms\Components\Fieldset::make('Крупный опт')
                            ->schema([
                                Forms\Components\TextInput::make('wholesale_large_threshold')
                                    ->label('Порог курсов (от)')
                                    ->numeric()
                                    ->default(10)
                                    ->minValue(1),
                                    
                                Forms\Components\TextInput::make('wholesale_large_discount')
                                    ->label('Скидка (%)')
                                    ->numeric()
                                    ->default(15)
                                    ->minValue(0)->maxValue(100),
                            ])->columns(2),
                    ])
                    ->visible(fn (Forms\Get $get) => $get('is_loyalty_active')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ToggleColumn::make('is_loyalty_active')
                    ->label('Лояльность включена'),
                    
                Tables\Columns\TextColumn::make('bundle_3_discount')
                    ->label('Макс. пакетная')
                    ->formatStateUsing(fn ($state) => $state . ' %'),
                    
                Tables\Columns\TextColumn::make('wholesale_large_discount')
                    ->label('Макс. накопительная')
                    ->formatStateUsing(fn ($state) => $state . ' %'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketingSettings::route('/'),
            'create' => Pages\CreateMarketingSetting::route('/create'),
            'edit' => Pages\EditMarketingSetting::route('/{record}/edit'),
        ];
    }
}