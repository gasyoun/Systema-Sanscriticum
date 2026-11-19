<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BotSubscriberResource\Pages;
use App\Models\BotSubscriber;
use App\Support\RoleGate;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Воронка @samskrte_bot»: кто нажимал /start, на каком шаге «Участвовать»
 * остановился, кто заблокировал бота. Только чтение — подписчиков создаёт и
 * двигает по шагам сам бот (OpenLessonBotService / OpenLessonFunnel).
 * Цифры воронки и рассылки по занятиям — виджеты над таблицей.
 */
class BotSubscriberResource extends Resource
{
    protected static ?string $model = BotSubscriber::class;

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?int $navigationSort = 136;

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Воронка @samskrte_bot';

    protected static ?string $pluralModelLabel = 'Подписчики @samskrte_bot';

    protected static ?string $modelLabel = 'Подписчик';

    /** Шаг анкеты «Участвовать» → подпись в таблице. */
    public const STEP_LABELS = [
        BotSubscriber::STEP_SUBSCRIBE => 'Подписка на канал',
        BotSubscriber::STEP_NAME => 'Имя',
        BotSubscriber::STEP_INTEREST => 'Интерес',
        BotSubscriber::STEP_TIME => 'Удобное время',
        BotSubscriber::STEP_DONE => 'Анкета заполнена',
    ];

    public static function canViewAny(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->label('Имя')
                    ->state(fn (BotSubscriber $r): string => $r->join_name ?: ($r->first_name ?: '—'))
                    ->description(fn (BotSubscriber $r): string => $r->username ? '@'.$r->username : 'chat '.$r->chat_id)
                    ->searchable(['first_name', 'join_name', 'username', 'chat_id']),
                Tables\Columns\TextColumn::make('source')
                    ->label('Откуда')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === BotSubscriber::SOURCE_LEAD ? 'лид магнита' : '/start')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('join_step')
                    ->label('Шаг «Участвовать»')
                    ->badge()
                    ->placeholder('не нажимал')
                    ->formatStateUsing(fn (?string $state): string => self::STEP_LABELS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => $state === BotSubscriber::STEP_DONE ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('join_interest')
                    ->label('Интерес')
                    ->formatStateUsing(fn (?string $state): string => (string) (config('open_lesson_cards.interests.'.$state) ?? $state))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('join_time')
                    ->label('Время')
                    ->formatStateUsing(fn (?string $state): string => (string) (config('open_lesson_cards.times.'.$state) ?? $state))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('consent_at')
                    ->label('Подписка и согласие')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('lead_id')
                    ->label('Лид')
                    ->formatStateUsing(fn (?int $state): string => '#'.$state)
                    ->url(fn (BotSubscriber $r): ?string => $r->lead_id ? LeadResource::getUrl('edit', ['record' => $r->lead_id]) : null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('blocked_at')
                    ->label('Заблокировал бота')
                    ->dateTime('d.m.Y')
                    ->placeholder('—')
                    ->color('danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Пришёл')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('join_step')
                    ->label('Шаг «Участвовать»')
                    ->options(self::STEP_LABELS),
                Tables\Filters\TernaryFilter::make('pressed_join')
                    ->label('Нажимал «Участвовать»')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('join_step'),
                        false: fn (Builder $q) => $q->whereNull('join_step'),
                    ),
                Tables\Filters\TernaryFilter::make('blocked')
                    ->label('Заблокировал бота')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('blocked_at'),
                        false: fn (Builder $q) => $q->whereNull('blocked_at'),
                    ),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Откуда')
                    ->options([BotSubscriber::SOURCE_START => '/start', BotSubscriber::SOURCE_LEAD => 'лид магнита']),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBotSubscribers::route('/'),
        ];
    }
}
