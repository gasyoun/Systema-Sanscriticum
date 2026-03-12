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
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name') // Дублируем для ID как на твоем скрине, или выводим реальный ID
                    ->label('ID')
                    ->searchable(),
                Tables\Columns\TextColumn::make('users_count')
                    ->counts('users') // Автоматически считает количество учеников в группе
                    ->label('Учеников'),
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
            // Убрали тот самый headerActions, который двоил кнопку
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
           //'create' => Pages\CreateGroup::route('/create'),
            //'edit' => Pages\EditGroup::route('/{record}/edit'),
        ];
    }
}
