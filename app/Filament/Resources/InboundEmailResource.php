<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\InboundEmailResource\Pages;
use App\Models\InboundEmail;
use App\Models\User;
use App\Services\Support\InboundEmailIngester;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * H3462: видимая очередь входящего email-канала. Письма нераспознанных
 * отправителей (status=queued) НЕ теряются — оператор видит их здесь и
 * привязывает к users вручную (аналог linked_user_id у TG-контактов).
 */
class InboundEmailResource extends Resource
{
    protected static ?string $model = InboundEmail::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $navigationLabel = 'Входящие письма';

    protected static ?int $navigationSort = 21;

    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() !== true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('from_email')
                    ->label('От кого')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subject')
                    ->label('Тема')
                    ->limit(50)
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === InboundEmail::STATUS_INGESTED ? 'Принято' : 'В очереди')
                    ->color(fn (string $state): string => $state === InboundEmail::STATUS_INGESTED ? 'success' : 'warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Пользователь')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('received_at')
                    ->label('Получено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        InboundEmail::STATUS_QUEUED => 'В очереди',
                        InboundEmail::STATUS_INGESTED => 'Принято',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('linkUser')
                    ->label('Привязать пользователя')
                    ->icon('heroicon-o-link')
                    ->visible(fn (InboundEmail $record): bool => $record->isQueued())
                    ->form([
                        Forms\Components\Select::make('user_id')
                            ->label('Пользователь')
                            ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->action(function (InboundEmail $record, array $data): void {
                        app(InboundEmailIngester::class)->linkToUser($record, (int) $data['user_id']);
                    })
                    ->successNotificationTitle('Письмо привязано и записано в диалог поддержки'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('from_name')->label('Имя отправителя')->placeholder('—'),
            TextEntry::make('from_email')->label('Email')->copyable(),
            TextEntry::make('message_id')->label('Message-ID')->copyable(),
            TextEntry::make('subject')->label('Тема')->placeholder('—'),
            TextEntry::make('received_at')->label('Получено')->dateTime('d.m.Y H:i:s'),
            TextEntry::make('text')->label('Текст письма')->prose()->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInboundEmails::route('/'),
            'view' => Pages\ViewInboundEmail::route('/{record}'),
        ];
    }
}
