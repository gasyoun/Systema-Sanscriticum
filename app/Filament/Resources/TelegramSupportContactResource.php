<?php

namespace App\Filament\Resources;

use App\Filament\Clusters\TelegramSupport;
use App\Filament\Resources\TelegramSupportContactResource\Pages;
use App\Models\TelegramSupportContact;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TelegramSupportContactResource extends Resource
{
    protected static ?string $model = TelegramSupportContact::class;

    protected static ?string $cluster = TelegramSupport::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $navigationLabel = 'Контакты';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() !== true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Telegram contact')
                ->schema([
                    Forms\Components\TextInput::make('telegram_user_id')
                        ->disabled(),
                    Forms\Components\TextInput::make('name')
                        ->disabled(),
                    Forms\Components\TextInput::make('username')
                        ->disabled(),
                    Forms\Components\TextInput::make('chat.telegram_chat_id')
                        ->label('Telegram chat ID')
                        ->disabled(),
                ])
                ->columns(2),
            Forms\Components\Section::make('Manual link')
                ->schema([
                    Forms\Components\Select::make('linked_user_id')
                        ->label('Linked user')
                        ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->nullable(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->placeholder('Unknown')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('username')
                    ->placeholder('No username')
                    ->searchable(),
                Tables\Columns\TextColumn::make('telegram_user_id')
                    ->label('Telegram user ID')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('chat.telegram_chat_id')
                    ->label('Chat ID')
                    ->searchable(),
                Tables\Columns\TextColumn::make('linkedUser.name')
                    ->label('Linked user')
                    ->placeholder('Unlinked')
                    ->searchable(),
                Tables\Columns\TextColumn::make('first_inbound_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('linked_user_id')
                    ->label('Linked')
                    ->nullable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('first_inbound_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramSupportContacts::route('/'),
            'edit' => Pages\EditTelegramSupportContact::route('/{record}/edit'),
        ];
    }
}
