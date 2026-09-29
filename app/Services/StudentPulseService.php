<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ежедневный пульс «активные платные ученики» (H4908).
 *
 * MG спросил «сколько у нас на сегодня активных платных учеников» (15-09-2026).
 * Рулинг MG в сессии: канон определения НЕ фиксируется — носитель (карточка
 * KPI-панели + дайджест) несёт ВСЕ кандидат-определения строками, канон будет
 * выбран словом позже. Поэтому сервис считает не одно число, а линейку.
 *
 * Полная read-only: ни payments, ни users не пишутся. Агрегаты без PII.
 * Staff (super_admin/manager/accountant/teacher) исключён всюду — та же
 * знаменательная база, что у census_student_profile_signals / юнит-экономики.
 *
 * Клубная таблица club_memberships («оплачено до», членский продукт H2566) в
 * пульс НЕ входит: живым SQL 15-09-2026 снято, что она покрывает 2 человек и
 * не описывает блок-платёжную модель школы (основные деньги — payments).
 *
 * Все окна считаются от переданного asOf (по умолчанию now()), чтобы тесты
 * были детерминированными и панель/дайджест видели один и тот же снимок.
 */
class StudentPulseService
{
    /** Роли staff — исключаются из всех знаменателей пульса. */
    public const STAFF_ROLES = ['super_admin', 'manager', 'accountant', 'teacher'];

    /**
     * Канонический фильтр плативших (согласован с FinanceCockpitReport /
     * DebtorsReport): статус из Payment::PAID_STATUSES, НЕ conditional
     * (обещание — не оплата), tariff НЕ из не-выручечных ('Расход' —
     * легаси-расходы школы, 'salary_payout' — выплаты ЗП; оба — не ученицкие
     * деньги), amount > 0. Без этого фильтра знаменатель завышен: аддендум
     * H4908 измерил +27 «неплательщиков» во «всего» (931 vs 904) и +10 в окне
     * ≤120д (201 vs 191). Условия включены inline в каждый запрос сервиса —
     * единая константа в PAYMENT_CANON_FILTER держит их синхронными.
     */
    private const NON_REVENUE_TARIFFS = ['Расход', 'salary_payout'];

    /**
     * Ось «покрывает опорный блок» — единственная, отвечающая на «сколько
     * учеников СЕЙЧАС на оплаченном курсе» в блок-оплатной школе (аддендум
     * H4908). Опорный блок курса берётся у DebtorsReport::referenceBlocks()
     * (не переизобретать датировку блоков в SQL). Живой замер 15-09: ≈215.
     */
    private function coveringReferenceBlock(): int
    {
        $refs = app(DebtorsReport::class)->referenceBlocks();

        if ($refs->isEmpty()) {
            return 0;
        }

        // Int-литералы встроены прямо в SQL: пара (course_id, ref_number) —
        // целые из доверенной коллекции models, инъекции неоткуда взяться,
        // а bindings в join(DB::raw()) не доезжают.
        $parts = [];
        foreach ($refs as $courseId => $block) {
            $parts[] = sprintf('SELECT %d AS course_id, %d AS ref_number', (int) $courseId, (int) $block->number);
        }
        $refSql = implode(' UNION ALL ', $parts);

        $row = Payment::query()
            ->join(DB::raw('('.$refSql.') AS ref'), 'ref.course_id', '=', 'payments.course_id')
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->where(fn ($q) => $q->whereNull('users.role')
                ->orWhereNotIn('users.role', self::STAFF_ROLES))
            ->whereIn('payments.status', Payment::PAID_STATUSES)
            ->where('payments.is_conditional', false)
            ->where(fn ($x) => $x->whereNull('payments.tariff')
                ->orWhereNotIn('payments.tariff', self::NON_REVENUE_TARIFFS))
            ->where('payments.amount', '>', 0)
            ->whereRaw('(
                    (payments.start_block IS NULL AND payments.end_block IS NULL)
                    OR (payments.start_block <= ref.ref_number AND payments.end_block >= ref.ref_number)
                    OR (payments.start_block <= ref.ref_number AND payments.end_block IS NULL)
                    OR (payments.start_block IS NULL AND payments.end_block >= ref.ref_number)
                )')
            ->distinct()
            ->count('payments.user_id');

        return (int) $row;
    }

    /**
     * Полный снимок пульса: кандидат-определения «активный платный ученик».
     *
     * @return array{
     *     as_of: string,
     *     paid_ever: int,
     *     paid_30d: int,
     *     paid_60d: int,
     *     paid_90d: int,
     *     paid_120d: int,
     *     paid_365d: int,
     *     repeat_120d: int,
     *     cabinet_active_30d: int,
     * }
     */
    public function snapshot(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();

        return [
            'as_of' => $asOf->toDateTimeString(),
            'paid_ever' => $this->distinctPayers($asOf, null),
            'paid_30d' => $this->distinctPayers($asOf, 30),
            'paid_60d' => $this->distinctPayers($asOf, 60),
            'paid_90d' => $this->distinctPayers($asOf, 90),
            'paid_120d' => $this->distinctPayers($asOf, 120),
            'paid_365d' => $this->distinctPayers($asOf, 365),
            'repeat_120d' => $this->repeatPayersWindow($asOf, 120),
            'learning_and_paying' => $this->learningAndPaying($asOf),
            'cabinet_active_30d' => $this->cabinetActiveAmongPayers($asOf, 30),
            'covering_ref_block' => $this->coveringReferenceBlock(),
        ];
    }

    /**
     * Кандидат-строки пульса в порядке убывания операционного интереса,
     * готовые к карточке и дайджесту. Канон не выбран (рулинг MG 15-09-2026:
     * «все варианты строками»), поэтому никакая строка не выделена как главная.
     *
     * @return list<string> строки вида «определение — N»
     */
    public function pulseLines(?Carbon $asOf = null): array
    {
        return $this->linesFromSnapshot($this->snapshot($asOf));
    }

    /**
     * Строки пульса из уже посчитанного снимка — чтобы панель и дайджест в
     * одном прогоне переиспользовали один и тот же результат запросов.
     *
     * @param  array<string, int|string>  $snap
     * @return list<string>
     */
    public function linesFromSnapshot(array $snap): array
    {
        return [
            sprintf('СЕЙЧАС на оплаченном блоке курса (опорный блок) — %d', $snap['covering_ref_block']),
            sprintf('учится в живой группе (занятие ±14 дн) И платил ≤90 дн — %d', $snap['learning_and_paying']),
            sprintf('платил ≤30 дн — %d', $snap['paid_30d']),
            sprintf('платил ≤60 дн — %d', $snap['paid_60d']),
            sprintf('платил ≤90 дн — %d', $snap['paid_90d']),
            sprintf('платил ≤120 дн — %d', $snap['paid_120d']),
            sprintf('≥2 оплат за ≤120 дн — %d', $snap['repeat_120d']),
            sprintf('кабинет-активные из плативших (вход ≤30 дн) — %d', $snap['cabinet_active_30d']),
            sprintf('всего плативших когда-либо — %d', $snap['paid_ever']),
        ];
    }

    /**
     * Одна компактная строка для карточки панели — самое узкое «платил ≤90 дн»
     * первым, всё остальное раскрывается в дайджесте строками.
     */
    public function headline(?Carbon $asOf = null): string
    {
        return $this->headlineFromSnapshot($this->snapshot($asOf));
    }

    /**
     * Головная строка из уже посчитанного снимка (без повторного запроса).
     *
     * @param  array<string, int|string>  $snap
     */
    public function headlineFromSnapshot(array $snap): string
    {
        return sprintf(
            'учится+платит: %d · опорный блок: %d · ≤90 дн: %d · всего: %d',
            $snap['learning_and_paying'],
            $snap['covering_ref_block'],
            $snap['paid_90d'],
            $snap['paid_ever'],
        );
    }

    /**
     * DISTINCT платившие с ХОТЯ БЫ ОДНОЙ оплатой статуса 'paid' за окно
     * (семантика живого SQL D2: «платил за последние N дн» — любая оплата,
     * не только первая). $days = null → за всю историю.
     */
    private function distinctPayers(Carbon $asOf, ?int $days): int
    {
        return (int) Payment::query()
            ->whereIn('payments.status', Payment::PAID_STATUSES)
            ->whereNotNull('payments.user_id')
            ->when($days !== null, fn ($q) => $q->where('payments.created_at', '>=', $asOf->copy()->subDays($days)))
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->where(fn ($q) => $q->whereNull('users.role')
                ->orWhereNotIn('users.role', self::STAFF_ROLES))
            ->where('payments.is_conditional', false)
            ->where(fn ($x) => $x->whereNull('payments.tariff')
                ->orWhereNotIn('payments.tariff', self::NON_REVENUE_TARIFFS))
            ->where('payments.amount', '>', 0)
            ->distinct()
            ->count('payments.user_id');
    }

    /**
     * DISTINCT платившие ≥2 каноническими оплатами за окно (ценз H4563).
     */
    private function repeatPayersWindow(Carbon $asOf, int $days): int
    {
        return (int) User::query()
            ->fromSub(
                Payment::query()
                    ->whereIn('payments.status', Payment::PAID_STATUSES)
                    ->whereNotNull('payments.user_id')
                    ->where('payments.created_at', '>=', $asOf->copy()->subDays($days))
                    ->join('users', 'users.id', '=', 'payments.user_id')
                    ->where(fn ($q) => $q->whereNull('users.role')
                        ->orWhereNotIn('users.role', self::STAFF_ROLES))
                    ->where('payments.is_conditional', false)
                    ->where(fn ($x) => $x->whereNull('payments.tariff')
                        ->orWhereNotIn('payments.tariff', self::NON_REVENUE_TARIFFS))
                    ->where('payments.amount', '>', 0)
                    ->select('payments.user_id')
                    ->groupBy('payments.user_id')
                    ->havingRaw('COUNT(*) >= 2')
                    ->getQuery(),
                'repeat_payers'
            )
            ->selectRaw('COUNT(*) AS n')
            ->value('n');
    }

    /**
     * Ось «учится сейчас И оплатил» (аддендум 15-09, PR #2816): ученик в живой
     * группе (есть занятие в `schedules` за ±14 дней) И имеет канон-оплату ≤90
     * дн. Число 103 на 15-09, устойчиво 98–106 при любом разумном окне «жива».
     * Это самое близкое к бытовому «активные платные ученики» — но канон всё
     * ещё выбирает MG словом; строка идёт рядом с остальными.
     */
    private function learningAndPaying(Carbon $asOf, int $payDays = 90, int $scheduleDays = 14): int
    {
        $since = $asOf->copy()->subDays($scheduleDays);

        return (int) User::query()
            ->whereIn('users.id', function ($q) use ($payDays, $asOf) {
                $q->select('payments.user_id')
                    ->from('payments')
                    ->whereIn('payments.status', Payment::PAID_STATUSES)
                    ->where('payments.is_conditional', false)
                    ->where('payments.amount', '>', 0)
                    ->where(fn ($x) => $x->whereNull('payments.tariff')
                        ->orWhereNotIn('payments.tariff', self::NON_REVENUE_TARIFFS))
                    ->where('payments.created_at', '>=', $asOf->copy()->subDays($payDays));
            })
            ->whereIn('users.id', function ($q) use ($since) {
                $q->select('gu.user_id')
                    ->from('group_user as gu')
                    ->whereNull('gu.left_at')
                    ->whereExists(function ($x) use ($since) {
                        $x->select(DB::raw(1))
                            ->from('schedules as s')
                            ->whereColumn('s.group_id', 'gu.group_id')
                            ->whereNull('s.deleted_at')
                            ->where('s.start', '>=', $since);
                    });
            })
            ->where(fn ($q) => $q->whereNull('users.role')
                ->orWhereNotIn('users.role', self::STAFF_ROLES))
            ->count();
    }

    /**
     * Платившие (когда-либо, канонически), заходившие в кабинет за окно —
     * H4004-семейство paid_active_30d, но со staff-исключением и явным asOf.
     */
    private function cabinetActiveAmongPayers(Carbon $asOf, int $days): int
    {
        return (int) User::query()
            ->whereIn('users.id', Payment::query()
                ->select('payments.user_id')
                ->whereIn('payments.status', Payment::PAID_STATUSES)
                ->whereNotNull('payments.user_id')
                ->where('payments.is_conditional', false)
                ->where(fn ($x) => $x->whereNull('payments.tariff')
                    ->orWhereNotIn('payments.tariff', self::NON_REVENUE_TARIFFS))
                ->where('payments.amount', '>', 0))
            ->where(fn ($q) => $q->whereNull('users.role')
                ->orWhereNotIn('users.role', self::STAFF_ROLES))
            ->where('users.last_login_at', '>=', $asOf->copy()->subDays($days))
            ->count();
    }
}
