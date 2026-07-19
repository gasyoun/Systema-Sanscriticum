<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Clusters\ZapisiBot;
use App\Filament\Resources\ZapisiClassScheduleResource\Pages;
use App\Models\ZapisiClassSchedule;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Track C (H164, D10): CRUD for the ad-hoc "записи" reminder schedule.
 * Independent of Lesson/Group/Schedule — see the migration docblock.
 */
class ZapisiClassScheduleResource extends Resource
{
    protected static ?string $model = ZapisiClassSchedule::class;

    protected static ?string $cluster = ZapisiBot::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Расписание';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('chat_id')
                ->label('Chat ID')
                ->required()
                ->helperText('@zapisi_ORSbot chat id (see MarketingSetting.zapisi_chat_id).'),
            Forms\Components\DateTimePicker::make('starts_at')
                ->label('Начало занятия')
                ->required()
                ->seconds(false),
            Forms\Components\Textarea::make('message_template')
                ->label('Текст напоминания')
                ->required()
                ->placeholder('Занятие начнётся сегодня в {time}.')
                ->helperText('{time} подставляется автоматически.')
                ->columnSpanFull(),
            Forms\Components\DateTimePicker::make('sent_at')
                ->label('Отправлено')
                ->disabled()
                ->seconds(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('chat_id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Начало')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('message_template')
                    ->label('Текст')
                    ->limit(50),
                Tables\Columns\IconColumn::make('sent_at')
                    ->label('Отправлено')
                    ->boolean()
                    ->getStateUsing(fn (ZapisiClassSchedule $record): bool => $record->sent_at !== null),
            ])
            ->defaultSort('starts_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListZapisiClassSchedules::route('/'),
            'create' => Pages\CreateZapisiClassSchedule::route('/create'),
            'edit' => Pages\EditZapisiClassSchedule::route('/{record}/edit'),
        ];
    }
}
