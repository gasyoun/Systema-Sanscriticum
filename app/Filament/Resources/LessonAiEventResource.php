<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LessonAiEventResource\Pages;
use App\Models\LessonAiEvent;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Журнал запросов к ИИ-помощнику занятия («Спроси Сократа», тест 24-11-2026).
 *
 * Read-only: строки пишет только App\Services\Knowledge\LessonAiEventLog, и он
 * же решает, можно ли сохранить текст вопроса и ответа — только тестировщику с
 * действующим согласием ai_test. Поэтому у части строк текста нет, и это
 * нормально: счётчики остаются у всех, тексты — только у согласившихся.
 *
 * Доступ — RoleGate::adminOnly() (админ и супер-админ). Куратору не открыт
 * сознательно: здесь лежат личные вопросы учеников, а не рабочая очередь.
 */
class LessonAiEventResource extends Resource
{
    protected static ?string $model = LessonAiEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?int $navigationSort = 96;

    protected static ?string $navigationLabel = 'ИИ-помощник занятия';

    protected static ?string $pluralModelLabel = 'Запросы к ИИ-помощнику';

    protected static ?string $modelLabel = 'запрос к ИИ-помощнику';

    /** Подписи задач в одном месте — и в колонке, и в фильтре. */
    public const TASK_LABELS = [
        'summary' => 'Пересказ',
        'quiz' => 'Самопроверка',
        'outline' => 'Конспект',
        'explain' => 'Объяснение',
        'ask' => 'Вопрос по занятию',
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

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user:id,name,email', 'lesson:id,title']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Ученик')
                    ->description(fn (LessonAiEvent $record): string => (string) $record->user?->email)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('lesson.title')
                    ->label('Урок')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('task')
                    ->label('Задача')
                    ->formatStateUsing(fn (string $state): string => self::TASK_LABELS[$state] ?? $state)
                    ->badge(),

                Tables\Columns\IconColumn::make('status')
                    ->label('Ответ')
                    ->icon(fn (string $state): string => $state === LessonAiEvent::STATUS_DONE
                        ? 'heroicon-o-check-circle'
                        : 'heroicon-o-x-circle')
                    ->color(fn (string $state): string => $state === LessonAiEvent::STATUS_DONE ? 'success' : 'danger')
                    ->tooltip(fn (LessonAiEvent $record): string => $record->error_code
                        ? 'Ошибка: '.$record->error_code
                        : (string) $record->status),

                Tables\Columns\TextColumn::make('source')
                    ->label('Источник')
                    ->formatStateUsing(fn (string $state): string => $state === LessonAiEvent::SOURCE_CACHED ? 'из кэша' : 'модель')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('latency_ms')
                    ->label('Время')
                    ->suffix(' мс')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('notes_chars')
                    ->label('Заметки')
                    ->suffix(' зн.')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('question')
                    ->label('Вопрос')
                    ->limit(50)
                    ->placeholder('— текст не сохранён —')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user')
                    ->label('Ученик')
                    ->relationship('user', 'name')
                    ->searchable(),

                Tables\Filters\SelectFilter::make('task')
                    ->label('Задача')
                    ->options(self::TASK_LABELS),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Ответ')
                    ->options([
                        LessonAiEvent::STATUS_DONE => 'Ответил',
                        LessonAiEvent::STATUS_ERROR => 'Ошибка',
                    ]),

                Tables\Filters\Filter::make('created_at')
                    ->label('Период')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('С'),
                        Forms\Components\DatePicker::make('until')->label('По'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Разобрать')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (LessonAiEvent $record): string => 'Запрос #'.$record->id)
                    ->modalContent(fn (LessonAiEvent $record) => view('filament.modals.lesson-ai-event', ['event' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть')
                    ->modalWidth('4xl'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLessonAiEvents::route('/'),
        ];
    }
}
