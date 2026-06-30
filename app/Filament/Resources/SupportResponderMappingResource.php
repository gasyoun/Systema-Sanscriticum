<?php

namespace App\Filament\Resources;

use App\Filament\Clusters\TelegramSupport;
use App\Filament\Resources\SupportResponderMappingResource\Pages;
use App\Models\SupportResponderMapping;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupportResponderMappingResource extends Resource
{
    protected static ?string $model = SupportResponderMapping::class;

    protected static ?string $cluster = TelegramSupport::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $navigationLabel = 'Ответчики';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() !== true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('marker_label')
                ->label('Marker / favicon label')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('user_id')
                ->label('Team member')
                ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable(),
            Forms\Components\Select::make('responder_type')
                ->options([
                    'human' => 'Human',
                    'ai' => 'AI',
                    'unknown' => 'Unknown',
                ])
                ->default('human')
                ->required(),
            Forms\Components\Toggle::make('is_active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('marker_label')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Team member')->searchable(),
                Tables\Columns\TextColumn::make('responder_type')->badge()->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportResponderMappings::route('/'),
            'create' => Pages\CreateSupportResponderMapping::route('/create'),
            'edit' => Pages\EditSupportResponderMapping::route('/{record}/edit'),
        ];
    }
}
