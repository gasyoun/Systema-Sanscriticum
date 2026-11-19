<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * H6145: «Финансы своих курсов» — read-only экран преподавателя. Преподаватель
 * видит платежи по своим курсам (курс → teacher_id) и прямые оплаты на своё
 * имя, плюс историю своих выплат; чужие курсы и общая касса школы недоступны.
 * Админ-подобные видят всё. Никаких правок денег отсюда: правки — только в
 * «Финансах» (PaymentResource), контур записи остался за admin/manager/accountant.
 * Повод: расчёт Костиной 05-10-2026 по памяти (учитель не видит оплат и не
 * может сверить ведомость) — H6141.
 */
class TeacherCoursePayments extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 71;

    protected static ?string $navigationLabel = 'Оплаты моих курсов';

    protected static ?string $title = 'Оплаты моих курсов';

    protected static ?string $slug = 'teacher-course-payments';

    protected static string $view = 'filament.pages.teacher-course-payments';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Fail-closed (P1 независимого ревью H6145): роль teacher без карточки
        // преподавателя (users.teacher_id = null, например после удаления
        // Teacher — FK nullOnDelete, роль остаётся) НЕ получает доступ.
        // Ветка «видно всё» — строго админ-подобные.
        return (bool) (($user?->isTeacher() && $user->teacher_id !== null) || $user?->isAdminLike());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Несуществующий id: пустой скоуп для вырожденного случая teacher без карточки. */
    private const EMPTY_SCOPE = -1;

    /** teacher_id для скоупа: у преподавателя — свой; null — только админ-подобные (всё). */
    private function scopeTeacherId(): ?int
    {
        $user = auth()->user();
        if ($user && $user->isTeacher() && ! $user->isAdminLike()) {
            // teacher без карточки: null НЕ должен означать «всё» — пустой скоуп
            return $user->teacher_id ?? self::EMPTY_SCOPE;
        }

        return null;
    }

    /** @return Collection<int, TeacherPayout> */
    public function payouts(): Collection
    {
        $teacherId = $this->scopeTeacherId();

        return TeacherPayout::query()
            ->when($teacherId !== null, fn (Builder $q) => $q->where('teacher_id', $teacherId))
            ->with('teacher')
            ->orderByDesc('paid_at')
            ->limit(30)
            ->get();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (Builder $query): Builder {
                $teacherId = $this->scopeTeacherId();
                if ($teacherId === null) {
                    return Payment::query()->with(['user', 'course']);
                }

                $courseIds = Course::query()->where('teacher_id', $teacherId)->pluck('id');

                return Payment::query()
                    ->with(['user', 'course'])
                    ->where(function (Builder $q) use ($courseIds, $teacherId): void {
                        $q->whereIn('course_id', $courseIds)
                            ->orWhere('received_by_teacher_id', $teacherId);
                    });
            })
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->searchPlaceholder('Ученик, email или курс…')
            ->defaultSort('first_paid_at', 'desc')
            ->columns([
                TextColumn::make('first_paid_at')
                    ->label('Дата оплаты')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Ученик')
                    ->searchable()
                    ->description(fn (Payment $r): ?string => $r->user?->email)
                    ->placeholder('—'),

                TextColumn::make('course.title')
                    ->label('Курс')
                    ->searchable()
                    ->description(fn (Payment $r): ?string => $r->start_block
                        ? 'блок '.$r->start_block.($r->end_block && $r->end_block !== $r->start_block ? '–'.$r->end_block : '')
                        : null)
                    ->placeholder('—'),

                TextColumn::make('amount')
                    ->label('Сумма')
                    ->getStateUsing(fn (Payment $r): string => number_format((float) $r->amount, 0, '.', ' ').' ₽')
                    ->description(fn (Payment $r): ?string => $r->foreignAmountLabel() ?: null)
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (Payment $r): string => Payment::statusColor($r->status))
                    ->getStateUsing(fn (Payment $r): string => Payment::statusLabel($r->status))
                    ->description(fn (Payment $r): ?string => $r->refund_of_payment_id
                        ? 'возврат к платежу #'.$r->refund_of_payment_id
                        : null),

                TextColumn::make('received_account')
                    ->label('Зачтено')
                    ->badge()
                    ->color(fn (Payment $r): string => $r->received_account === Payment::RECEIVED_TEACHER ? 'success' : 'gray')
                    ->getStateUsing(fn (Payment $r): string => $r->received_account === Payment::RECEIVED_TEACHER
                        ? 'на счёт преподавателя'
                        : 'на счёт школы'),
            ])
            ->filters([
                SelectFilter::make('course')
                    ->label('Курс')
                    ->options(function (): array {
                        $teacherId = $this->scopeTeacherId();

                        return Course::query()
                            ->when($teacherId !== null, fn (Builder $q) => $q->where('teacher_id', $teacherId))
                            ->orderBy('title')
                            ->pluck('title', 'id')
                            ->all();
                    })
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $courseId) => $q->where('payments.course_id', $courseId),
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

                Filter::make('period')
                    ->label('Период оплаты')
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
                        // coalesce: у части строк first_paid_at пуст, реальная дата — created_at
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, string $date) => $q->whereDate(
                                    DB::raw('coalesce(payments.first_paid_at, payments.created_at)'),
                                    '>=',
                                    $date,
                                ),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, string $date) => $q->whereDate(
                                    DB::raw('coalesce(payments.first_paid_at, payments.created_at)'),
                                    '<=',
                                    $date,
                                ),
                            );
                    }),
            ]);
    }

    /** Подпись шапки: чьи курсы показаны. */
    public function scopeLabel(): string
    {
        $teacherId = $this->scopeTeacherId();

        return $teacherId !== null
            ? (Teacher::find($teacherId)?->name ?? '—')
            : 'админ-контур: все преподаватели';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'scopeName' => $this->scopeLabel(),
        ];
    }
}
