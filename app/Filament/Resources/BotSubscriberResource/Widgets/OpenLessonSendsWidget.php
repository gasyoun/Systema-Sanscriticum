<?php

declare(strict_types=1);

namespace App\Filament\Resources\BotSubscriberResource\Widgets;

use App\Filament\Resources\ScheduleResource;
use App\Models\OpenLessonCardSend;
use App\Models\Schedule;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Занятия с галочкой «анонс в боте»: сколько карточек доставлено по стадиям
 * (только sent_at — недоставленные и отказы не считаются) и сколько ответили
 * «✅ Я иду» / «Не смогу».
 */
class OpenLessonSendsWidget extends TableWidget
{
    protected static ?string $heading = 'Открытые занятия в боте';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $delivered = fn (string $stage): \Closure => fn (Builder $q) => $q->where('stage', $stage)->whereNotNull('sent_at');

        return $table
            ->query(
                Schedule::query()
                    ->where('bot_card_enabled', true)
                    ->withCount([
                        'openLessonCardSends as announce_count' => $delivered(OpenLessonCardSend::STAGE_ANNOUNCE),
                        'openLessonCardSends as morning_count' => $delivered(OpenLessonCardSend::STAGE_MORNING),
                        'openLessonCardSends as zoom_count' => $delivered(OpenLessonCardSend::STAGE_ZOOM),
                        'openLessonRsvps as going_count' => fn (Builder $q) => $q->whereNotNull('going_at'),
                        'openLessonRsvps as declined_count' => fn (Builder $q) => $q->whereNotNull('declined_at'),
                    ])
            )
            ->defaultSort('start', 'desc')
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Ни одно занятие не отмечено для анонса в боте')
            ->emptyStateDescription('Галочка «Открытое занятие: анонс в боте» — в карточке занятия в расписании.')
            ->columns([
                TextColumn::make('start')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Занятие')
                    ->wrap()
                    ->url(fn (Schedule $r): string => ScheduleResource::getUrl('edit', ['record' => $r])),
                TextColumn::make('announce_count')->label('Анонс')->alignCenter(),
                TextColumn::make('morning_count')->label('Утро')->alignCenter(),
                TextColumn::make('going_count')->label('✅ Я иду')->alignCenter()->color('success')->weight('bold'),
                TextColumn::make('declined_count')->label('Не смогу')->alignCenter(),
                TextColumn::make('zoom_count')->label('Ссылка Zoom')->alignCenter(),
            ]);
    }
}
