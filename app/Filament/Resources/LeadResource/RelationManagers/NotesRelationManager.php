<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadResource\RelationManagers;

use App\Models\LeadNote;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'История общения';

    protected static ?string $icon = 'heroicon-o-chat-bubble-left-right';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Textarea::make('body')
                ->label('Текст')
                ->rows(4)
                ->required()
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => LeadNote::TYPES[$state] ?? ($state ?? '—'))
                    ->color(fn (?string $state): string => match ($state) {
                        LeadNote::TYPE_EMAIL => 'info',
                        LeadNote::TYPE_DM => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('channel')
                    ->label('Канал')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'telegram' => 'TG',
                        'vk' => 'VK',
                        'max' => 'Max',
                        'email' => 'Email',
                        default => '—',
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('author.name')
                    ->label('Автор')
                    ->placeholder('Система'),
                Tables\Columns\TextColumn::make('body')
                    ->label('Текст')
                    ->wrap()
                    ->limit(120),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Добавить заметку')
                    ->icon('heroicon-o-plus')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['type'] = LeadNote::TYPE_NOTE;
                        $data['user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (LeadNote $record): bool => $record->type === LeadNote::TYPE_NOTE),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
