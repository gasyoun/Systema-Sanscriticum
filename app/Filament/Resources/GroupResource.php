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
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Название группы')
                ->required(),
            Forms\Components\TextInput::make('slug')
                ->label('Технический ID')
                ->default('g' . time())
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Название')->sortable(),
                Tables\Columns\TextColumn::make('slug')->label('ID'),
                Tables\Columns\TextColumn::make('users_count')->counts('users')->label('Учеников'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->modal(), // Модалка
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->modal(), // Модалка создания
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
        ];
    }
}
