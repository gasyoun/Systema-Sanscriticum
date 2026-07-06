<?php

namespace App\Filament\Resources;

use App\Filament\Clusters\TelegramSupport;
use App\Filament\Resources\SupportTopicRuleResource\Pages;
use App\Models\SupportTopicRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupportTopicRuleResource extends Resource
{
    protected static ?string $model = SupportTopicRule::class;

    protected static ?string $cluster = TelegramSupport::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $navigationLabel = 'Темы';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() !== true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('category')
                ->required()
                ->maxLength(255),
            // No ->separator(): TagsInput must store an ARRAY to match the model's
            // `keywords => array` cast. With a separator it stores one delimited
            // STRING, which the cast then re-encodes as a JSON scalar — the
            // classifier's foreach then gets a string and matches nothing.
            Forms\Components\TagsInput::make('keywords')
                ->required(),
            Forms\Components\TextInput::make('priority')
                ->numeric()
                ->default(100)
                ->required(),
            Forms\Components\Toggle::make('is_enabled')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('category')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('keywords')->badge()->separator(','),
                Tables\Columns\TextColumn::make('priority')->sortable(),
                Tables\Columns\IconColumn::make('is_enabled')->boolean(),
            ])
            ->defaultSort('priority')
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
            'index' => Pages\ListSupportTopicRules::route('/'),
            'create' => Pages\CreateSupportTopicRule::route('/create'),
            'edit' => Pages\EditSupportTopicRule::route('/{record}/edit'),
        ];
    }
}
