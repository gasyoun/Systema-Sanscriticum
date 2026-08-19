<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ChatRemovalStatus;
use App\Filament\Resources\UserResource;
use App\Models\CourseDebtChatRemoval;
use App\Services\Discipline\ChatRemovalCandidate;
use App\Services\Discipline\ChatRemovalEligibility;
use App\Services\Discipline\ChatRemovalLedger;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Рабочий экран оператора по правилу H2746: кандидаты на исключение из учебного
 * TG-чата и реестр уже исключённых со взносами за возврат.
 *
 * Инструкция оператору живёт ЗДЕСЬ, рядом с кнопками, а не в репозитории:
 * список строится из живых запросов и разойтись с экраном не может, а фамилии
 * и суммы не уезжают в публичный трекер.
 *
 * Wave 1: страница НЕ трогает Telegram. Кик по-прежнему делает человек кнопкой
 * «Исключить из TG-чата» на странице «Должники»; здесь он фиксирует, что кик
 * состоялся, и дальше ведёт долг, взнос и возврат.
 */
class CourseDebtChatRemovals extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationLabel = 'Исключения из чатов';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $title = 'Исключения из учебных чатов и взносы за возврат';

    protected static ?string $slug = 'chat-removals';

    protected static string $view = 'filament.pages.course-debt-chat-removals';

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::adminOnly();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $eligibility = app(ChatRemovalEligibility::class);
        $all = $eligibility->candidates();

        return [
            'rule' => [
                'days' => $eligibility->minDaysOverdue(),
                'contacts' => $eligibility->minUnansweredContacts(),
                'fee' => $eligibility->reinstatementFee(),
                'currency' => (string) config('chat_removal.currency', 'RUB'),
                'autoTelegram' => (bool) config('chat_removal.auto_telegram_mutation', false),
            ],
            'candidates' => $all->filter(fn (ChatRemovalCandidate $c) => $c->isEligible())->values(),
            'rejected' => $all->reject(fn (ChatRemovalCandidate $c) => $c->isEligible())->values(),
        ];
    }

    /**
     * Зафиксировать состоявшееся исключение: строка реестра создаётся и сразу
     * помечается removed. Одним действием, потому что оператор нажимает кнопку
     * здесь ровно после того, как выгнал студента из чата, — разводить это на
     * два клика значит гарантированно получить строки в стадии «подтверждён»,
     * про которые никто не помнит, был кик или нет.
     */
    public function recordRemoval(int $userId, int $courseId, string $chatId): void
    {
        $candidate = app(ChatRemovalEligibility::class)
            ->candidates($userId, $courseId)
            ->first(fn (ChatRemovalCandidate $c) => $c->telegramChatId === $chatId && $c->isEligible());

        if ($candidate === null) {
            Notification::make()
                ->title('Кандидат больше не проходит правило')
                ->body('Данные изменились с момента загрузки страницы — обновите её.')
                ->danger()
                ->send();

            return;
        }

        try {
            $ledger = app(ChatRemovalLedger::class);
            $actor = auth()->id() ? (int) auth()->id() : null;
            $removal = $ledger->qualify($candidate, $actor);
            $ledger->markRemoved($removal, $actor);
        } catch (Throwable $e) {
            Notification::make()->title('Не записано')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Записано в реестр')
            ->body('Взнос за возврат в этот чат: '
                .$candidate->reinstatementFee.' '
                .config('chat_removal.currency', 'RUB').'.')
            ->success()
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CourseDebtChatRemoval::query()->with(['user', 'course']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Студент')
                    ->searchable()
                    ->url(fn (CourseDebtChatRemoval $r): string => UserResource::getUrl('edit', ['record' => $r->user_id]))
                    ->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('chat_label')->label('Чат'),
                Tables\Columns\TextColumn::make('course.title')->label('Курс')->limit(28),
                Tables\Columns\TextColumn::make('status')
                    ->label('Стадия')
                    ->badge()
                    ->formatStateUsing(fn (ChatRemovalStatus $state): string => $state->label())
                    ->color(fn (ChatRemovalStatus $state): string => match ($state) {
                        ChatRemovalStatus::Restored => 'success',
                        ChatRemovalStatus::Cancelled => 'gray',
                        ChatRemovalStatus::FeeSettled => 'info',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('days_overdue')->label('дн. просрочки'),
                Tables\Columns\TextColumn::make('debt_amount')->label('Долг')->money('RUB'),
                Tables\Columns\TextColumn::make('reinstatement_fee')->label('Взнос')->money('RUB'),
                Tables\Columns\TextColumn::make('fee_status')
                    ->label('Взнос')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        CourseDebtChatRemoval::FEE_SETTLED => 'оплачен',
                        CourseDebtChatRemoval::FEE_WAIVED => 'прощён',
                        default => 'ждём',
                    }),
                Tables\Columns\TextColumn::make('removed_at')->label('Исключён')->date('d.m.Y'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Стадия')
                    ->options(ChatRemovalEligibility::statusLabels()),
            ])
            ->actions([
                $this->debtSettledAction(),
                $this->feePaidAction(),
                $this->feeWaivedAction(),
                $this->restoreAction(),
                $this->cancelAction(),
            ])
            ->emptyStateHeading('Реестр пуст')
            ->emptyStateDescription('Ни одного исключения за курсовой долг ещё не зафиксировано.');
    }

    private function debtSettledAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('debt_settled')
            ->label('Долг погашен')
            ->icon('heroicon-o-banknotes')
            ->visible(fn (Model $r): bool => $r->status->isOpen() && ! $r->debtIsSettled())
            ->requiresConfirmation()
            ->modalDescription('Отмечает, что КУРСОВОЙ долг закрыт. Взнос за возврат в чат это не закрывает.')
            ->action(fn (Model $r) => $this->run(fn (ChatRemovalLedger $l) => $l->markDebtSettled($r, $this->actor())));
    }

    private function feePaidAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('fee_paid')
            ->label('Взнос оплачен')
            ->icon('heroicon-o-check-circle')
            ->visible(fn (Model $r): bool => $r->status->isOpen() && ! $r->feeIsClosed())
            ->requiresConfirmation()
            ->modalDescription(fn (Model $r): string => 'Взнос за возврат именно в этот чат: '
                .(int) $r->reinstatement_fee.' '.$r->fee_currency.'.')
            ->action(fn (Model $r) => $this->run(fn (ChatRemovalLedger $l) => $l->markFeePaid($r, null, $this->actor())));
    }

    private function feeWaivedAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('fee_waived')
            ->label('Простить взнос')
            ->icon('heroicon-o-hand-raised')
            ->color('gray')
            ->visible(fn (Model $r): bool => $r->status->isOpen() && ! $r->feeIsClosed())
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('Причина')
                    ->required()
                    ->helperText('Останется в аудит-следе. «По решению куратора» — не причина.'),
            ])
            ->action(fn (Model $r, array $data) => $this->run(
                fn (ChatRemovalLedger $l) => $l->waiveFee($r, (string) $data['reason'], $this->actor())
            ));
    }

    private function restoreAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('restore')
            ->label('Возвращён в чат')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->visible(fn (Model $r): bool => $r->status->allowsRestoration())
            ->form([
                Forms\Components\Textarea::make('note')->label('Комментарий')->rows(2),
            ])
            ->requiresConfirmation()
            ->modalDescription('Разбан делается вручную в дашборде «Записи (бот)»; здесь фиксируется факт возврата.')
            ->action(fn (Model $r, array $data) => $this->run(
                fn (ChatRemovalLedger $l) => $l->markRestored($r, $this->actor(), $data['note'] ?? null)
            ));
    }

    private function cancelAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('cancel')
            ->label('Отменить эпизод')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Model $r): bool => $r->status->isOpen())
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label('Почему основание отпало')
                    ->required(),
            ])
            ->action(fn (Model $r, array $data) => $this->run(
                fn (ChatRemovalLedger $l) => $l->cancel($r, (string) $data['reason'], $this->actor())
            ));
    }

    private function actor(): ?int
    {
        return auth()->id() ? (int) auth()->id() : null;
    }

    /** Общая обёртка: доменные отказы показываем оператору текстом, а не 500. */
    private function run(callable $fn): void
    {
        try {
            $fn(app(ChatRemovalLedger::class));
            Notification::make()->title('Готово')->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Отклонено')->body($e->getMessage())->danger()->send();
        }
    }
}
