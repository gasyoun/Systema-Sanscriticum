<?php

namespace App\Filament\Resources;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Ученики';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([Forms\Components\Section::make('Личные данные')
                ->schema([
                    Forms\Components\TextInput::make('name')->required()->label('Имя'),
                    Forms\Components\TextInput::make('email')->email()->required()->label('Email'),
                    Forms\Components\TextInput::make('phone')->tel()->label('Телефон'),
                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->dehydrateStateUsing(fn ($state) => \Hash::make($state))
                        ->required(fn (string $context): bool => $context === 'create')
                        ->label('Пароль'),
                ])->columns(2),

            Forms\Components\Section::make('Маркетинг и Связи')
                ->schema([
                    Forms\Components\TextInput::make('telegram_id')->label('Telegram ID')->numeric(),
                    Forms\Components\TextInput::make('source')->label('Источник (откуда пришел)'),
                    Forms\Components\KeyValue::make('social_links')
                        ->label('Соцсети')
                        ->addActionLabel('Добавить ссылку')
                        ->keyLabel('Название (VK, Insta)')
                        ->valueLabel('URL адрес'),
                ])->collapsed(), // Свернуто, чтобы не мешать

            Forms\Components\Section::make('Доступ к обучению')
                ->schema([
                    Forms\Components\Select::make('groups')
                        ->label('Группы доступа')
                        ->multiple()
                        ->relationship('groups', 'name')
                        ->preload(),
                ]),
]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Имя'),
                Tables\Columns\TextColumn::make('email')->label('Email'),
                Tables\Columns\TextColumn::make('groups.name')->label('Группы')->badge(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array { return ['index' => UserResource\Pages\ListUsers::route('/')]; }
}
