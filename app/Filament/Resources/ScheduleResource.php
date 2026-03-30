<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScheduleResource\Pages;
use App\Models\Schedule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ScheduleResource extends Resource
{
    protected static ?string $model = Schedule::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar'; // <-- Иконка календаря
    protected static ?int $navigationSort = 40;
    protected static ?string $navigationGroup = 'Обучение';
    protected static ?string $navigationLabel = 'Расписание';
    protected static ?string $pluralModelLabel = 'Расписание';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Событие')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Название события')
                            ->required()
                            ->columnSpanFull(),
                        
                        Forms\Components\Textarea::make('description')
                            ->label('Описание / Ссылка')
                            ->columnSpanFull(),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\DateTimePicker::make('start')
                                    ->label('Начало')
                                    ->required(),
                                Forms\Components\DateTimePicker::make('end')
                                    ->label('Окончание (необязательно)'),
                            ]),
                    ]),

                Forms\Components\Section::make('Настройки')
                    ->schema([
                        Forms\Components\Select::make('group_id')
                            ->relationship('group', 'name')
                            ->label('Для группы (Пусто = для всех)')
                            ->searchable()
                            ->preload(),
                            
                        Forms\Components\ColorPicker::make('color')
                            ->label('Цвет метки')
                            ->default('#3788d8'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('start')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('title')
                    ->label('Событие')
                    ->searchable()
                    ->description(fn (Schedule $record) => \Illuminate\Support\Str::limit($record->description, 50)),
                
                Tables\Columns\TextColumn::make('group.name')
                    ->label('Группа')
                    ->badge()
                    ->placeholder('Для всех'),

                Tables\Columns\ColorColumn::make('color')
                    ->label('Цвет'),
            ])
            ->filters([
                // Тут могут быть фильтры
            ])
            ->actions([
                // Действия для ОДНОЙ строки
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]) 
            // ВАЖНО: Мы закрыли actions(), и теперь вызываем bulkActions() как отдельный метод
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Удалить выбранные'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSchedules::route('/'),
            'create' => Pages\CreateSchedule::route('/create'),
            'edit' => Pages\EditSchedule::route('/{record}/edit'),
        ];
    }
}