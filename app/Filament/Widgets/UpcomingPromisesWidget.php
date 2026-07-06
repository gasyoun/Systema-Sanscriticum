<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\Debtors;
use App\Jobs\SendTelegramMessageJob;
use App\Models\PaymentPromise;
use App\Models\PromiseEvent;
use App\Services\CuratorNotifier;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Дашборд-виджет «Скоро срок»: показывает обещания оплаты, у которых
 * promised_at ∈ [сегодня; +7 дней]. Менеджеру не нужно лезть в страницу
 * «Должники» и фильтровать самому — самые горячие пары выводятся здесь.
 * Просроченные (active со сроком в прошлом) тоже попадают — они в первую
 * очередь требуют решения «отозвать или продлить».
 */
class UpcomingPromisesWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Скоро срок оплаты (7 дней)';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                PaymentPromise::query()
                    ->with(['user', 'course'])
                    ->where('status', PaymentPromise::STATUS_ACTIVE)
                    ->whereDate('promised_at', '<=', now()->addDays(7)->toDateString())
                    ->orderBy('promised_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('promised_at')
                    ->label('Срок')
                    ->date('d.m.Y')
                    ->badge()
                    ->color(fn (PaymentPromise $r): string => $r->isOverdue() ? 'danger' : 'warning')
                    ->formatStateUsing(fn ($state, PaymentPromise $r): string => $r->isOverdue()
                        ? 'просрочено '.$r->promised_at->format('d.m.Y')
                        : $r->promised_at->format('d.m.Y')),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Студент')
                    ->description(fn (PaymentPromise $r): string => '#'.$r->user_id
                        .($r->user?->is_unreliable ? ' · 🚩' : '')),
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->wrap(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->formatStateUsing(fn ($state): string => $state !== null
                        ? number_format((float) $state, 0, '.', ' ').' ₽'
                        : '—')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('actual_paid_at')
                    ->label('Факт. оплата')
                    ->date('d.m.Y')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('installment_group_id')
                    ->label('Рассрочка')
                    ->formatStateUsing(fn (?string $state): string => $state ? 'да' : '—'),
            ])
            ->actions([
                Tables\Actions\Action::make('open_debtors')
                    ->label('К должникам')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(Debtors::getUrl()),
            ])
            ->bulkActions([
                self::nudgeBulkAction(),
                self::waiveBulkAction(),
            ])
            ->emptyStateHeading('На ближайшую неделю обещаний нет')
            ->emptyStateDescription('Если ни одно обещание не подходит к сроку, виджет молчит.')
            ->paginated([5, 10, 25]);
    }

    /**
     * Bulk «Напомнить»: пинг студенту в Telegram по выбранным обещаниям
     * + событие nudged в журнал. Только куратор/админ.
     */
    private static function nudgeBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('nudge')
            ->label('Напомнить студенту')
            ->icon('heroicon-o-bell-alert')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Отправит студентам напоминание в Telegram и запишет событие в журнал.')
            ->visible(fn (): bool => RoleGate::any(Roles::ADMIN, Roles::MANAGER))
            ->action(function (Collection $records): void {
                $sent = 0;
                $skipped = 0;
                foreach ($records as $promise) {
                    /** @var PaymentPromise $promise */
                    if (! $promise->user_id) {
                        $skipped++;

                        continue;
                    }
                    SendTelegramMessageJob::dispatch($promise->user_id, self::nudgeText($promise));
                    PromiseEvent::log($promise, PromiseEvent::EVENT_NUDGED);
                    $sent++;
                }

                Notification::make()
                    ->title('Напоминания отправлены: '.$sent)
                    ->body($skipped > 0 ? "Без Telegram пропущено: {$skipped}" : null)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Bulk «Простить долг»: закрывает обещание как отменённое (долг списан),
     * пишет событие waived и уведомляет куратора. Только куратор/админ.
     */
    private static function waiveBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('waive')
            ->label('Простить долг')
            ->icon('heroicon-o-hand-raised')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Простить долг по обещанию')
            ->modalDescription('Обещание будет закрыто как отменённое, долг списан. Действие фиксируется в журнале.')
            ->visible(fn (): bool => RoleGate::any(Roles::ADMIN, Roles::MANAGER))
            ->action(function (Collection $records): void {
                $waived = 0;
                foreach ($records as $promise) {
                    /** @var PaymentPromise $promise */
                    if (! $promise->isUnmet()) {
                        continue; // уже оплачено/отменено — не трогаем
                    }
                    $promise->update([
                        'status' => PaymentPromise::STATUS_CANCELLED,
                        'cancelled_at' => now(),
                    ]);
                    PromiseEvent::log($promise, PromiseEvent::EVENT_WAIVED);
                    app(CuratorNotifier::class)->promiseCancelled($promise);
                    $waived++;
                }

                Notification::make()->title('Прощено обещаний: '.$waived)->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** Текст напоминания студенту о подошедшем сроке оплаты. */
    private static function nudgeText(PaymentPromise $promise): string
    {
        $courseName = $promise->course->title ?? 'курс';
        $url = url('/login');
        $amount = $promise->amount !== null
            ? number_format((float) $promise->amount, 0, '.', ' ').' ₽'
            : 'договорённая сумма';

        $text = "⏰ <b>Напоминание об оплате</b>\n\n";
        $text .= "Намасте! По курсу <b>«{$courseName}»</b> подошёл срок оплаты ({$amount}).\n\n";
        $text .= "Чтобы сохранить доступ — оплатите, пожалуйста:\n";
        $text .= "<a href='{$url}'>Перейти в личный кабинет</a>";

        return $text;
    }

    protected function getTableQuery(): Builder
    {
        return PaymentPromise::query()
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->whereDate('promised_at', '<=', now()->addDays(7)->toDateString());
    }
}
