<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Payment;
use App\Models\Teacher;
use App\Support\RoleGate;
use App\Support\Roles;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * H4627: единый реестр «прямых» оплат — всех платежей, зачтённых в гонорар
 * преподавателя (received_account = teacher_personal): и занесённые вручную
 * до появления анкеты, и пришедшие через анкету /teacher-pay (анкета).
 * Одна страница отвечает на вопросы куратора «когда/кто/за что уже занесено»
 * и страхует от двойного занесения одного и того же платежа. Read-only:
 * правки — только в «Платежах». Доступ — RoleGate::finance()
 * (тот же контур доступа, что ресурс «Финансы»: админ + менеджер + бухгалтер).
 */
class TeacherDirectPaymentsLedger extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Прямые оплаты преподавателям';

    protected static ?int $navigationSort = 47;

    protected static ?string $title = 'Прямые оплаты преподавателям — полный реестр';

    protected static ?string $slug = 'teacher-direct-payments';

    protected static string $view = 'filament.pages.teacher-direct-payments';

    public static function canAccess(): bool
    {
        // Тот же контур, что и ресурс «Финансы» (PaymentResource): ledger не
        // открывает новых данных, только делает прямые оплаты находимыми.
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER, Roles::ACCOUNTANT);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Payment::query()
                ->where('received_account', Payment::RECEIVED_TEACHER)
                ->with(['user', 'course', 'receivedByTeacher']))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->searchPlaceholder('Ученик, email или курс…')
            ->groups([
                Group::make('receivedByTeacher.name')
                    ->label('Преподаватель курса')
                    ->getTitleFromRecordUsing(fn (Payment $r): string => $r->receivedByTeacher?->name ?? '—')
                    ->collapsible(),
            ])
            ->columns([
                TextColumn::make('paid_on_label')
                    ->label('Дата оплаты')
                    ->getStateUsing(fn (Payment $r): string => (string) ($r->claimMeta('paid_on') ?: $r->created_at?->format('d.m.Y')))
                    ->description(fn (Payment $r): ?string => $r->created_at?->format('d.m.Y') !== ($r->claimMeta('paid_on') ?: null)
                        ? 'занесено '.$r->created_at?->format('d.m.Y')
                        : null)
                    ->sortable(['created_at']),

                TextColumn::make('user.name')
                    ->label('Ученик')
                    ->searchable()
                    ->description(fn (Payment $r): ?string => $r->user?->email)
                    ->placeholder('—'),

                TextColumn::make('receivedByTeacher.name')
                    ->label('Преподаватель курса')
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('course.title')
                    ->label('Курс')
                    ->placeholder('—')
                    ->searchable()
                    ->description(fn (Payment $r): ?string => $r->operationLabel()),

                TextColumn::make('foreign_amount')
                    ->label('Перевёл')
                    ->getStateUsing(fn (Payment $r): string => $r->foreignAmountLabel() ?: '—')
                    ->alignRight(),

                TextColumn::make('amount')
                    ->label('Номинал')
                    ->getStateUsing(fn (Payment $r): string => number_format((float) $r->amount, 0, '.', ' ').' ₽')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (Payment $r): string => Payment::statusColor($r->status))
                    ->getStateUsing(fn (Payment $r): string => Payment::statusLabel($r->status)),

                TextColumn::make('source')
                    ->label('Источник')
                    ->badge()
                    ->color(fn (Payment $r): string => $r->isTeacherTransfer() ? 'success' : 'gray')
                    ->getStateUsing(fn (Payment $r): string => $r->isTeacherTransfer()
                        ? 'анкета /teacher-pay'
                        : 'внесён вручную')
                    ->tooltip(fn (Payment $r): ?string => $r->payer_note),
            ])
            ->filters([
                SelectFilter::make('teacher')
                    ->label('Преподаватель курса')
                    ->options(fn (): array => Teacher::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $teacherId) => $q->where('payments.received_by_teacher_id', $teacherId),
                    )),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'pending' => 'Ожидает сверки',
                        'paid' => 'Оплачен',
                        'canceled' => 'Отменён',
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, string $status) => $q->where('payments.status', $status),
                    )),

                SelectFilter::make('source')
                    ->label('Источник')
                    ->options([
                        'claim' => 'Анкета /teacher-pay',
                        'manual' => 'Внесён вручную',
                    ])
                    ->query(function (Builder $query, array $data) {
                        match ($data['value'] ?? null) {
                            'claim' => $query->where('payments.provider', Payment::PROVIDER_TEACHER_TRANSFER),
                            'manual' => $query->where(function (Builder $q) {
                                $q->whereNull('payments.provider')
                                    ->orWhere('payments.provider', '!=', Payment::PROVIDER_TEACHER_TRANSFER);
                            }),
                            default => null,
                        };
                    }),

                Filter::make('period')
                    ->label('Период занесения')
                    ->form([
                        DatePicker::make('from')
                            ->label('С даты')
                            ->native(false)
                            ->displayFormat('d.m.Y'),
                        DatePicker::make('until')
                            ->label('По дату')
                            ->native(false)
                            ->displayFormat('d.m.Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, string $date) => $q->whereDate('payments.created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, string $date) => $q->whereDate('payments.created_at', '<=', $date),
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
            ->actions([])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Прямых оплат пока нет')
            ->emptyStateDescription('Здесь появятся все платежи, зачтённые в гонорар преподавателей — и занесённые вручную, и пришедшие через анкету /teacher-pay.');
    }
}
