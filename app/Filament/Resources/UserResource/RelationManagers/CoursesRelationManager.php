<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CoursesRelationManager extends RelationManager
{
    protected static string $relationship = 'courses';

    // Как таблица будет называться в интерфейсе
    protected static ?string $title = 'Обучается на курсах';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('status')
                    ->label('Статус обучения')
                    ->options([
                        'Записался' => 'Записался',
                        'Рассрочка' => 'Рассрочка',
                        'Приостановка' => 'Приостановка',
                        'Льготник' => 'Льготник',
                        'Вернулся' => 'Вернулся',
                        'Выпускник' => 'Выпускник',
                        'Покинул' => 'Покинул',
                        'Исключен' => 'Исключен',
                    ])
                    ->default('Записался')
                    ->required()
                    ->live(),

                Forms\Components\TextInput::make('left_after_block')
                    ->label('Блок выхода')
                    ->numeric()
                    ->minValue(1)
                    ->visible(fn (Forms\Get $get): bool => in_array(
                        $get('status'),
                        ['Покинул', 'Исключен', 'Выпускник'],
                        true,
                    ))
                    ->helperText('Номер блока этого курса, после которого студент вышел/был отчислен/выпустился. Долги по более поздним блокам не начисляются.'),

                Forms\Components\Textarea::make('note')
                    ->label('Примечание (только по этому курсу)')
                    ->maxLength(65535)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        $ownerId = $this->getOwnerRecord()->getKey();

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Название курса')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Записался', 'Вернулся', 'Выпускник' => 'success',
                        'Рассрочка', 'Приостановка', 'Льготник' => 'warning',
                        'Покинул', 'Исключен' => 'danger',
                        default => 'gray',
                    })
                    // Под бейджем — когда записался: дата первой реальной оплаты
                    // курса. Для записей без оплаты (ручная/льготная) — дата
                    // появления записи в системе. (pivot created_at у legacy =
                    // дата импорта, поэтому опираемся на платёж.)
                    ->description(function ($record) use ($ownerId): ?string {
                        $firstPaid = \App\Models\Payment::query()
                            ->where('user_id', $ownerId)
                            ->where('course_id', $record->id)
                            ->whereIn('status', ['paid', 'success'])
                            ->where('amount', '>', 0)
                            ->min('created_at');

                        if ($firstPaid) {
                            return 'записан '.\Illuminate\Support\Carbon::parse($firstPaid)->format('d.m.Y');
                        }

                        return $record->pivot?->created_at
                            ? 'в системе с '.$record->pivot->created_at->format('d.m.Y')
                            : null;
                    }),

                Tables\Columns\TextColumn::make('left_after_block')
                    ->label('Блок выхода')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): ?string => $state ? '№'.$state : null)
                    ->getStateUsing(fn ($record) => $record->pivot?->left_after_block),

                Tables\Columns\TextColumn::make('note')
                    ->label('Примечание')
                    ->wrap() // Позволяет длинному тексту переноситься на новые строки
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Кнопка для ручного добавления студента на курс прямо из его карточки
                Tables\Actions\AttachAction::make()
                    ->label('Записать на курс')
                    ->preloadRecordSelect()
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options([
                                'Записался' => 'Записался',
                                'Рассрочка' => 'Рассрочка',
                                'Льготник' => 'Льготник',
                            ])
                            ->default('Записался')
                            ->required(),
                        Forms\Components\Textarea::make('note')
                            ->label('Примечание'),
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Изменить статус')
                    ->iconButton()
                    ->tooltip('Редактировать статус и примечание'),
                Tables\Actions\DetachAction::make()
                    ->label('Отвязать')
                    ->iconButton()
                    ->tooltip('Удалить с курса'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
