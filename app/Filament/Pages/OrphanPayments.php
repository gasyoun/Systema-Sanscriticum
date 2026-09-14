<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Payment;
use App\Services\OrphanPaymentsReport;
use App\Support\RoleGate;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

/**
 * H3913: Filament-страница «Сиротские платежи» — read-only список оплат без
 * привязки к студенту (user_id NULL либо аккаунт супер-админа), сгруппированный
 * по сумме, с колонкой-подсказкой кандидата (совпадение по сумме + близкой
 * дате среди учеников курса). Привязок/правок со страницы НЕ делается:
 * разбор «чьи это деньги» — ручная работа куратора. Доступ — как у
 * «Должников» (RoleGate::adminOnly).
 */
class OrphanPayments extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Сиротские платежи';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $title = 'Сиротские платежи';

    protected static ?string $slug = 'orphan-payments';

    protected static string $view = 'filament.pages.orphan-payments';

    private ?OrphanPaymentsReport $reportMemo = null;

    private function report(): OrphanPaymentsReport
    {
        return $this->reportMemo ??= app(OrphanPaymentsReport::class);
    }

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::adminOnly();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->report()->query())
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->groups([
                Group::make('amount')
                    ->label('Сумма')
                    ->getTitleFromRecordUsing(fn (Payment $r): string => self::money($r->amount))
                    ->collapsible(),
                Group::make('created_at')
                    ->label('Дата')
                    ->getTitleFromRecordUsing(fn (Payment $r): string => $r->created_at?->format('d.m.Y') ?? '—')
                    ->collapsible(),
                Group::make('course_id')
                    ->label('Курс')
                    ->getTitleFromRecordUsing(fn (Payment $r): string => $r->course?->title ?? '—')
                    ->collapsible(),
            ])
            ->defaultGroup('amount')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable()
                    ->description(fn (Payment $r): ?string => $r->first_paid_at?->format('d.m.Y') !== $r->created_at?->format('d.m.Y')
                        ? 'оплачен '.$r->first_paid_at?->format('d.m.Y')
                        : null),

                TextColumn::make('amount')
                    ->label('Сумма')
                    ->getStateUsing(fn (Payment $r): string => self::money($r->amount))
                    ->alignRight()
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('orphan_reason')
                    ->label('Привязка')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'нет user_id' ? 'danger' : 'warning')
                    ->getStateUsing(fn (Payment $r): string => $r->user_id === null
                        ? 'нет user_id'
                        : 'супер-админ · '.($r->user?->name ?? '#'.$r->user_id)),

                TextColumn::make('course.title')
                    ->label('Курс')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('tariff')
                    ->label('Тариф')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('blocks')
                    ->label('Блоки')
                    ->getStateUsing(fn (Payment $r): string => self::blocks($r))
                    ->alignCenter()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('transaction_id')
                    ->label('Квитанция')
                    ->copyable()
                    ->placeholder('—')
                    ->description(fn (Payment $r): ?string => $r->payer_note)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('candidates')
                    ->label('Кандидат (подсказка)')
                    ->getStateUsing(fn (Payment $r): ?string => $this->report()->candidateLabel($r))
                    ->placeholder('нет зацепок')
                    ->color('info')
                    ->wrap()
                    ->tooltip('Best-effort: ученики этого курса, платившие ту же сумму; Δ — разрыв в днях с датой сироты. Не привязка, а зацепка для сверки.'),
            ])
            ->filters([
                SelectFilter::make('course_id')
                    ->label('Курс')
                    ->options(fn (): array => $this->report()->courseTitles())
                    ->query(fn ($query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($q, $courseId) => $q->where('payments.course_id', $courseId),
                    )),

                SelectFilter::make('orphan_type')
                    ->label('Тип сироты')
                    ->options([
                        'no_user' => 'Без user_id',
                        'super_admin' => 'Аккаунт супер-админа',
                    ])
                    ->query(function ($query, array $data) {
                        match ($data['value'] ?? null) {
                            'no_user' => $query->whereNull('payments.user_id'),
                            'super_admin' => $query->whereHas('user', fn ($uq) => $uq->where('role', 'super_admin')),
                            default => null,
                        };
                    }),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Сиротских платежей нет')
            ->emptyStateDescription('Все оплаченные платежи привязаны к студентам.');
    }

    private static function money($amount): string
    {
        return number_format((float) $amount, 0, '.', ' ').' ₽';
    }

    private static function blocks(Payment $r): string
    {
        $start = $r->start_block !== null ? (int) $r->start_block : null;
        $end = $r->end_block !== null ? (int) $r->end_block : null;

        if ($start === null && $end === null) {
            return 'весь курс';
        }
        if ($start !== null && $end !== null) {
            return $start === $end ? "блок №{$start}" : "блоки №{$start}–{$end}";
        }
        if ($start !== null) {
            return "с блока №{$start}";
        }

        return "по блок №{$end}";
    }
}
