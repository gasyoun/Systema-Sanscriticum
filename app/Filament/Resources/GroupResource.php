<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GroupResource\Pages;
use App\Models\Group;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Группы';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Данные группы')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->label('Название группы'),
                            
                        // --- НОВЫЙ БЛОК: ИНТЕРАКТИВНЫЙ СПИСОК УЧЕНИКОВ ---
                        Forms\Components\Select::make('users')
                            ->relationship('users', 'name') // Автоматически подтягивает и сохраняет связи
                            ->multiple()                    // Позволяет выбрать нескольких
                            ->preload()                     // Подгружает первые результаты сразу
                            ->searchable()                  // Включает поиск по имени
                            ->label('Ученики в группе')
                            ->placeholder('Начните вводить имя ученика...')
                            ->helperText('Здесь вы можете посмотреть текущих участников, удалить их или добавить новых.'),
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Исправил дубль: теперь тут выводится реальный ID группы
                Tables\Columns\TextColumn::make('id') 
                    ->label('ID')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                // --- ОБНОВЛЕННАЯ КОЛОНКА УЧЕНИКОВ ---
                Tables\Columns\TextColumn::make('users_count')
                    ->counts('users') 
                    ->label('Учеников')
                    ->badge() // Делает цифру красивым бейджиком
                    ->color('info') // Синий цвет
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
            // 'create' => Pages\CreateGroup::route('/create'),
            // 'edit' => Pages\EditGroup::route('/{record}/edit'),
        ];
    }
}