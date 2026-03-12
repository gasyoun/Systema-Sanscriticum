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

    // Разрешаем создать только ОДНУ запись настроек на весь сайт
    public static function canCreate(): bool
    {
        return MarketingSetting::count() === 0;
    }

    // Запрещаем удалять настройки
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Программа лояльности "Для своих"')
                    ->description('Скидка автоматически применяется ко всем студентам, у которых есть хотя бы один оплаченный курс в прошлом.')
                    ->schema([
                        Forms\Components\Toggle::make('is_loyalty_active')
                            ->label('Включить автоматическую скидку выпускникам')
                            ->default(false),
                            
                        Forms\Components\TextInput::make('loyalty_discount_percent')
                            ->label('Размер скидки (%)')
                            ->numeric()
                            ->default(15)
                            ->minValue(1)
                            ->maxValue(99)
                            ->required(),
                    ])->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ToggleColumn::make('is_loyalty_active')
                    ->label('Программа лояльности включена'),
                    
                Tables\Columns\TextColumn::make('loyalty_discount_percent')
                    ->label('Размер скидки')
                    ->formatStateUsing(fn ($state) => $state . ' %'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->paginated(false); // Отключаем пагинацию, так как запись всего одна
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