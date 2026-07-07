<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\Payment;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Read-only список всех оплат студента прямо в его карточке.
 * Колонки и фильтры (курс / блок / статус / период) зеркалят PaymentResource —
 * единый источник стиля. Редактирование платежей — в разделе «Платежи».
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Оплаты';

    protected static ?string $recordTitleAttribute = 'id';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color('gray')
                    ->size('sm'),

                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс и тариф')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(function (Payment $record): string {
                        $label = $record->operationLabel();

                        if ($record->tariff === 'deposit' && $record->deposit_consumed_at) {
                            $label .= ' · зачтено '.$record->deposit_consumed_at->format('d.m.Y');
                        } elseif ($record->tariff === 'deposit' && (float) ($record->consumed_amount ?? 0) > 0) {
                            $label .= ' · зачтено частично: '.number_format((float) $record->consumed_amount, 0, ',', ' ').' ₽';
                        }

                        return $label;
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('RUB', locale: 'ru')
                    ->sortable()
                    ->weight(FontWeight::ExtraBold)
                    ->color(fn (Payment $record) => $record->amount < 0 ? 'danger' : ($record->status === 'paid' ? 'success' : 'gray'))
                    ->alignment(Alignment::End),

                Tables\Columns\TextColumn::make('discount')
                    ->label('Скидка')
                    ->badge()
                    ->color('success')
                    ->getStateUsing(fn (Payment $record): ?string => $record->discountLabel() ?: null)
                    ->alignment(Alignment::Center),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (?string $state): string => Payment::statusColor($state))
                    ->formatStateUsing(fn (?string $state): string => Payment::statusLabel($state))
                    ->alignment(Alignment::Center),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('course_id')
                    ->label('Фильтр по курсу')
                    // Только курсы, по которым у этого студента реально есть оплаты.
                    ->options(fn (): array => Payment::query()
                        ->where('user_id', $this->getOwnerRecord()->getKey())
                        ->whereNotNull('course_id')
                        ->join('courses', 'courses.id', '=', 'payments.course_id')
                        ->distinct()
                        ->orderBy('courses.title')
                        ->pluck('courses.title', 'payments.course_id')
                        ->toArray()),

                // Кто оплатил конкретный блок (зеркало PaymentResource): поблочные
                // покупки, чей диапазон содержит блок, И покупатели всего курса (full).
                Tables\Filters\Filter::make('paid_block')
                    ->label('Оплаченный блок')
                    ->form([
                        Forms\Components\TextInput::make('block')
                            ->label('Блок №')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('напр. 2')
                            ->helperText('Сузьте «Фильтром по курсу» выше'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query->when($data['block'] ?? null, function ($q, $block) {
                            $n = (int) $block;

                            $q->paid()
                                ->where(function ($q2) use ($n) {
                                    $q2->where('tariff', 'full')
                                        ->orWhere('tariff', 'block_'.$n)
                                        ->orWhere(fn ($q3) => $q3
                                            ->whereNotNull('start_block')
                                            ->where('start_block', '<=', $n)
                                            ->where(fn ($q4) => $q4
                                                ->where('end_block', '>=', $n)
                                                ->orWhere(fn ($q5) => $q5
                                                    ->whereNull('end_block')
                                                    ->where('start_block', $n))));
                                });
                        });
                    })
                    ->indicateUsing(function (array $data): array {
                        if (! ($data['block'] ?? null)) {
                            return [];
                        }

                        return ['Оплачен блок №'.$data['block']];
                    }),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Фильтр по статусу')
                    ->options([
                        'pending' => 'Ожидает оплаты',
                        'paid' => 'Оплачено',
                        'canceled' => 'Отменено',
                    ]),

                Tables\Filters\Filter::make('created_at')
                    ->label('Период')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('С даты')
                            ->native(false)
                            ->displayFormat('d.m.Y'),
                        Forms\Components\DatePicker::make('until')
                            ->label('По дату')
                            ->native(false)
                            ->displayFormat('d.m.Y'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn ($q, $date) => $q->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn ($q, $date) => $q->whereDate('created_at', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'С '.Carbon::parse($data['from'])->format('d.m.Y');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'По '.Carbon::parse($data['until'])->format('d.m.Y');
                        }

                        return $indicators;
                    }),
            ])
            // Read-only: добавление/правка платежей — в полноценном разделе «Платежи».
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->emptyStateHeading('Оплат пока нет')
            ->emptyStateIcon('heroicon-o-banknotes');
    }
}
