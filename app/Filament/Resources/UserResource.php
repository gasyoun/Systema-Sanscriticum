<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Студенты';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Имя')
                    ->required()
                    ->maxLength(255),
                    
                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255),
                    
                Forms\Components\TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create'),

                Forms\Components\Select::make('groups')
                    ->label('Состоит в группах')
                    ->multiple()
                    ->relationship('groups', 'name')
                    ->preload()
                    ->columnSpanFull(),
                
                Forms\Components\Toggle::make('is_admin')
                    ->label('Права администратора')
                    ->helperText('Дает полный доступ в панель управления')
                    ->onColor('success')
                    ->offColor('danger')
                // ЭТУ ГАЛОЧКУ УВИДИТ ТОЛЬКО СУПЕР-АДМИН:
                    ->visible(fn () => auth()->user()->email === 'pe4kinsmart@gmail.com'),    
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Email скопирован'),
                    
                Tables\Columns\TextColumn::make('groups.name')
                    ->label('Группы')
                    ->badge()
                    ->color('success')
                    ->separator(','),
                    
                Tables\Columns\IconColumn::make('is_admin')
                    ->label('Админ')
                    ->boolean()
                    // КОЛОНКУ УВИДИТ ТОЛЬКО СУПЕР-АДМИН:
                    ->visible(fn () => auth()->user()->email === 'pe4kinsmart@gmail.com'),    
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
            // Оставляем только index, чтобы все открывалось в красивых всплывающих окнах
            'index' => Pages\ListUsers::route('/'),
        ];
    }
}
