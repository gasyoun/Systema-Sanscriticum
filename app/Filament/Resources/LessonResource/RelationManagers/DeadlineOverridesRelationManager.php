<?php

declare(strict_types=1);

namespace App\Filament\Resources\LessonResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class DeadlineOverridesRelationManager extends RelationManager
{
    protected static string $relationship = 'deadlineOverrides';

    protected static ?string $title = 'Индивидуальные сроки ДЗ';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')
                ->label('Студент')
                ->relationship('user', 'name')
                ->searchable()
                ->preload()
                ->helperText('Если задан студент — это правило важнее группового.'),
            Forms\Components\Select::make('group_id')
                ->label('Группа')
                ->relationship('group', 'name')
                ->searchable()
                ->preload()
                ->helperText('Используйте для продления срока всей группе.'),
            Forms\Components\DateTimePicker::make('opens_at')
                ->label('Открыть сдачу')
                ->seconds(false),
            Forms\Components\DateTimePicker::make('due_at')
                ->label('Дедлайн')
                ->seconds(false),
            Forms\Components\DateTimePicker::make('locks_at')
                ->label('Закрыть сдачу')
                ->seconds(false)
                ->helperText('Пусто = через 1 день после дедлайна.'),
            Forms\Components\TextInput::make('reason')
                ->label('Причина')
                ->maxLength(255)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Студент')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('group.name')
                    ->label('Группа')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('opens_at')
                    ->label('Открытие')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('сразу'),
                Tables\Columns\TextColumn::make('due_at')
                    ->label('Дедлайн')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('нет'),
                Tables\Columns\TextColumn::make('locks_at')
                    ->label('Закрытие')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('+1 день'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
