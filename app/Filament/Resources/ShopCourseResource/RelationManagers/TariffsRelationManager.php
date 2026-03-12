<?php

namespace App\Filament\Resources\ShopCourseResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TariffsRelationManager extends RelationManager
{
    // Имя связи в модели Course (должно быть tariffs)
    protected static string $relationship = 'tariffs';
    
    // Заголовок блока на странице
    protected static ?string $title = 'Тарифы и цены';
    
    protected static ?string $recordTitleAttribute = 'title';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->label('Название тарифа (например: Базовый)')
                    ->maxLength(255),
                    
                Forms\Components\TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->label('Стоимость (₽)')
                    ->minValue(0),
                    
                Forms\Components\Textarea::make('description')
                    ->label('Что входит в тариф (кратко)')
                    ->rows(3)
                    ->columnSpanFull(),
                    
                Forms\Components\Toggle::make('is_active')
                    ->label('Доступен для покупки')
                    ->default(true)
                    ->onColor('success'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // Убираем лишние тени, чтобы органично смотрелось внутри курса
            ->modifyQueryUsing(fn ($query) => $query->latest()) 
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Название')
                    ->weight('bold'),
                    
                Tables\Columns\TextColumn::make('price')
                    ->label('Цена')
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, '.', ' ') . ' ₽'),
                    
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Активен'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Добавить тариф')
                    ->icon('heroicon-o-plus'),
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
}