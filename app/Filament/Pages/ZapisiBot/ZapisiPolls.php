<?php

declare(strict_types=1);

namespace App\Filament\Pages\ZapisiBot;

use App\Filament\Actions\SendPollToChatAction;
use App\Filament\Clusters\ZapisiBot;
use App\Jobs\SendZapisiPollJob;
use App\Models\Group;
use App\Models\TelegramPoll;
use App\Models\TelegramPollAnswer;
use App\Services\Telegram\ZapisiPollService;
use App\Support\RoleGate;
use Filament\Actions\Action as PageAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Опросы @zapisi_ORSbot в чатах групп: история, результаты поимённо, закрытие.
 * Отправка — «Новый опрос» здесь или «Опрос в чат» на строке группы
 * (SendPollToChatAction). Голоса пишет ZapisiPollService::recordAnswer().
 */
class ZapisiPolls extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $cluster = ZapisiBot::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Опросы';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Опросы в чатах групп';

    protected static ?string $slug = 'polls';

    protected static string $view = 'filament.pages.zapisi-bot.polls';

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    protected function getHeaderActions(): array
    {
        return [
            PageAction::make('new_poll')
                ->label('Новый опрос')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => (bool) config('features.telegram_zapisi_bot'))
                ->modalHeading('Новый опрос в чат группы')
                ->modalSubmitActionLabel('Отправить опрос')
                ->form([
                    Select::make('group_id')
                        ->label('Группа')
                        ->options(fn (): array => Group::query()
                            ->whereNotNull('telegram_chat_id')
                            ->where('telegram_chat_id', '!=', '')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->helperText('Только группы с привязанным Telegram-чатом.'),
                    ...SendPollToChatAction::formSchema(),
                ])
                ->action(fn (array $data) => SendPollToChatAction::submit(Group::find($data['group_id'] ?? null), $data)),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => TelegramPoll::query()->with(['group', 'answers', 'creator'])->latest())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i', 'Europe/Moscow'),
                TextColumn::make('group.name')->label('Группа')->searchable(),
                TextColumn::make('question')->label('Вопрос')->limit(70)->wrap()->searchable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TelegramPoll::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        TelegramPoll::STATUS_SENT => 'success',
                        TelegramPoll::STATUS_PENDING => 'info',
                        TelegramPoll::STATUS_FAILED, TelegramPoll::STATUS_UNKNOWN => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('voters')
                    ->label('Проголосовало')
                    ->state(fn (TelegramPoll $record): int => $record->answers
                        ->reject(fn (TelegramPollAnswer $a): bool => $a->isRetracted())
                        ->count()),
                TextColumn::make('creator.name')->label('Автор')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('group_id')->label('Группа')->relationship('group', 'name')->searchable(),
            ])
            ->actions([
                Action::make('results')
                    ->label('Результаты')
                    ->icon('heroicon-o-list-bullet')
                    ->modalHeading(fn (TelegramPoll $record): string => $record->question)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть')
                    ->modalContent(fn (TelegramPoll $record) => view('filament.pages.zapisi-bot.poll-results', [
                        'poll' => $record,
                        'tally' => $record->optionTally(),
                        'retracted' => $record->answers()->with('user')->get()->filter->isRetracted()->values(),
                    ])),
                Action::make('close')
                    ->label('Закрыть опрос')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn (TelegramPoll $record): bool => $record->status === TelegramPoll::STATUS_SENT)
                    ->requiresConfirmation()
                    ->modalDescription('Голосование в чате остановится, результаты останутся.')
                    ->action(function (TelegramPoll $record): void {
                        try {
                            app(ZapisiPollService::class)->close($record);
                            Notification::make()->title('Опрос закрыт')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Не удалось закрыть')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('resend')
                    ->label('Отправить ещё раз')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (TelegramPoll $record): bool => $record->status === TelegramPoll::STATUS_FAILED)
                    ->action(function (TelegramPoll $record): void {
                        SendZapisiPollJob::dispatch($record->id);
                        Notification::make()->title('Опрос снова отправляется')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Опросов пока нет')
            ->emptyStateDescription('«Новый опрос» вверху или «Опрос в чат» на строке учебной группы.')
            ->paginated([10, 25, 50]);
    }
}
