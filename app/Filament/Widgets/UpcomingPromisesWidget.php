<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\Debtors;
use App\Models\PaymentPromise;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

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
            ->emptyStateHeading('На ближайшую неделю обещаний нет')
            ->emptyStateDescription('Если ни одно обещание не подходит к сроку, виджет молчит.')
            ->paginated([5, 10, 25]);
    }

    protected function getTableQuery(): Builder
    {
        return PaymentPromise::query()
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->whereDate('promised_at', '<=', now()->addDays(7)->toDateString());
    }
}
