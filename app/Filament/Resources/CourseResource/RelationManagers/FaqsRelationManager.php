<?php

namespace App\Filament\Resources\CourseResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class FaqsRelationManager extends RelationManager
{
    protected static string $relationship = 'faqs';

    protected static ?string $title = 'FAQ по курсу';

    protected static ?string $recordTitleAttribute = 'question';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('question')
                    ->label('Вопрос')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('answer')
                    ->label('Ответ')
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('is_visible')
                    ->label('Показывать')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('question')
                    ->label('Вопрос')
                    ->wrap()
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_visible')
                    ->label('Виден')
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Добавить вопрос')
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
