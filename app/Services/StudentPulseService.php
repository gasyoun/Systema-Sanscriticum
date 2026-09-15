<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;

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
            'cabinet_active_30d' => $this->cabinetActiveAmongPayers($asOf, 30),
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
            '≤90 дн: %d · ≤120 дн: %d · всего: %d',
            $snap['paid_90d'],
            $snap['paid_120d'],
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
            ->where('payments.status', 'paid')
            ->whereNotNull('payments.user_id')
            ->when($days !== null, fn ($q) => $q->where('payments.created_at', '>=', $asOf->copy()->subDays($days)))
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->where(fn ($q) => $q->whereNull('users.role')
                ->orWhereNotIn('users.role', self::STAFF_ROLES))
            ->distinct()
            ->count('payments.user_id');
    }

    /**
     * DISTINCT платившие ≥2 оплатами за окно (ценз H4563 «активные платящие»).
     */
    private function repeatPayersWindow(Carbon $asOf, int $days): int
    {
        return (int) User::query()
            ->fromSub(
                Payment::query()
                    ->where('payments.status', 'paid')
                    ->whereNotNull('payments.user_id')
                    ->where('payments.created_at', '>=', $asOf->copy()->subDays($days))
                    ->join('users', 'users.id', '=', 'payments.user_id')
                    ->where(fn ($q) => $q->whereNull('users.role')
                        ->orWhereNotIn('users.role', self::STAFF_ROLES))
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
     * Платившие (когда-либо), заходившие в кабинет за окно — H4004-семейство
     * paid_active_30d, но со staff-исключением и явным asOf.
     */
    private function cabinetActiveAmongPayers(Carbon $asOf, int $days): int
    {
        return (int) User::query()
            ->whereIn('users.id', Payment::query()
                ->select('user_id')
                ->where('status', 'paid')
                ->whereNotNull('user_id'))
            ->where(fn ($q) => $q->whereNull('users.role')
                ->orWhereNotIn('users.role', self::STAFF_ROLES))
            ->where('users.last_login_at', '>=', $asOf->copy()->subDays($days))
            ->count();
    }
}
