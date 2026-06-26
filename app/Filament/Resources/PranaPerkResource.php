<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\PranaPerkResource\Pages;
use App\Models\PranaPerk;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PranaPerkResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = PranaPerk::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?int $navigationSort = 130;

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Магазин праны';

    protected static ?string $pluralModelLabel = 'Перки (магазин праны)';

    protected static ?string $modelLabel = 'Перк';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Название')->required()->maxLength(255)->columnSpanFull(),
            Forms\Components\Textarea::make('description')
                ->label('Описание')->rows(2)->columnSpanFull(),
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\TextInput::make('cost')
                    ->label('Цена (прана)')->numeric()->required()->minValue(1),
                Forms\Components\TextInput::make('stock')
                    ->label('Остаток (пусто = безлимит)')->numeric()->minValue(0)
                    ->helperText('Сколько штук доступно. Оставьте пустым для безлимита.'),
                Forms\Components\TextInput::make('icon')
                    ->label('Иконка (эмодзи)')->maxLength(8)->placeholder('🎁'),
            ]),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Toggle::make('is_active')->label('Активен')->default(true),
                Forms\Components\TextInput::make('sort_order')->label('Порядок')->numeric()->default(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('icon')->label('')->width('1%'),
                Tables\Columns\TextColumn::make('name')->label('Перк')->searchable()->weight('bold')->wrap(),
                Tables\Columns\TextColumn::make('cost')->label('Цена 🪷')->sortable()->alignEnd(),
                Tables\Columns\TextColumn::make('stock')->label('Остаток')->placeholder('∞')->alignEnd(),
                Tables\Columns\TextColumn::make('redemptions_count')->counts('redemptions')->label('Куплено')->alignEnd(),
                Tables\Columns\IconColumn::make('is_active')->label('Активен')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPranaPerks::route('/'),
            'create' => Pages\CreatePranaPerk::route('/create'),
            'edit' => Pages\EditPranaPerk::route('/{record}/edit'),
        ];
    }
}
