<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\PaymentPromise;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DebtorsReport
{
    private const PAID_STATUSES = ['paid', 'success'];

    /** @var Collection<int, CourseBlock>|null  course_id => reference CourseBlock */
    private ?Collection $referenceBlocksCache = null;

    /** @var array<int, array<int, Tariff>>  course_id => [block_number => block-Tariff] */
    private array $blockTariffsCache = [];

    /** @var array<string, PaymentPromise|null>  "user_id:course_id" => активный/просроченный promise */
    private array $promiseCache = [];

    /**
     * Reference-блок для каждого активного курса:
     * 1) `is_current=true`; 2) текущий по датам (now ∈ [starts_at; ends_at]);
     * 3) ближайший предстоящий (`starts_at > now`).
     * Курсы без подходящего блока в выборку не попадают.
     *
     * @return Collection<int, CourseBlock>
     */
    public function referenceBlocks(): Collection
    {
        if ($this->referenceBlocksCache !== null) {
            return $this->referenceBlocksCache;
        }

        $now = Carbon::now();
        $today = $now->copy()->startOfDay();

        $courseIds = Course::query()->where('is_active', true)->pluck('id');

        $blocks = CourseBlock::query()
            ->whereIn('course_id', $courseIds)
            ->get()
            ->groupBy('course_id');

        $result = collect();
        foreach ($blocks as $courseId => $courseBlocks) {
            $manual = $courseBlocks->firstWhere('is_current', true);
            if ($manual !== null) {
                $result->put((int) $courseId, $manual);

                continue;
            }

            $byDate = $courseBlocks->first(fn (CourseBlock $b) => $b->starts_at !== null
                && $b->ends_at !== null
                && $b->starts_at->lte($now)
                && $b->ends_at->gte($today)
            );
            if ($byDate !== null) {
                $result->put((int) $courseId, $byDate);

                continue;
            }

            $upcoming = $courseBlocks
                ->filter(fn (CourseBlock $b) => $b->starts_at !== null && $b->starts_at->gt($now))
                ->sortBy('starts_at')
                ->first();
            if ($upcoming !== null) {
                $result->put((int) $courseId, $upcoming);
            }
        }

        return $this->referenceBlocksCache = $result;
    }

    /**
     * Основной Builder для Filament-таблицы.
     * Корневая модель — User, к каждому ряду через joinSub добавлены поля:
     *   course_id, ref_block_number, debt_type ('not_renewed'|'no_payment'),
     *   last_payment_id (id последнего paid Payment пары, NULL для no_payment).
     *
     * @return Builder<User>
     */
    public function query(): Builder
    {
        $refs = $this->referenceBlocks();

        if ($refs->isEmpty()) {
            return User::query()->whereRaw('1 = 0');
        }

        [$pairsSql, $pairsBindings] = $this->buildPairsUnion($refs);

        $sub = DB::query()->fromRaw('('.$pairsSql.') AS d', $pairsBindings);

        return User::query()
            ->joinSub($sub, 'd', 'd.user_id', '=', 'users.id')
            ->select([
                'users.*',
                'd.course_id',
                'd.ref_block_number',
                'd.debt_type',
                'd.last_payment_id',
            ]);
    }

    /**
     * Карта "{course_id}:{number}" → CourseBlock — для PHP-обогащения
     * Filament-таблицы (даты блока).
     *
     * @return array<string, CourseBlock>
     */
    public function blocksLookup(): array
    {
        $lookup = [];
        foreach ($this->referenceBlocks() as $courseId => $block) {
            $lookup[$courseId.':'.$block->number] = $block;
        }

        return $lookup;
    }

    /**
     * @return array<int, string>  course_id => title
     */
    public function courseTitles(): array
    {
        $ids = $this->referenceBlocks()->keys()->all();

        return Course::query()->whereIn('id', $ids)->pluck('title', 'id')->all();
    }

    /**
     * Собирает SQL пар (user_id, course_id, ref_block_number, debt_type,
     * last_payment_id) — UNION ALL not_renewed + no_payment.
     *
     * @param  Collection<int, CourseBlock>  $refs
     * @return array{0: string, 1: array<int, scalar>}
     */
    private function buildPairsUnion(Collection $refs): array
    {
        [$refSql, $refBindings] = $this->buildReferenceInlineSql($refs);

        $paidIn = implode(',', array_fill(0, count(self::PAID_STATUSES), '?'));
        $nullIntCast = DB::connection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        // A: not_renewed — есть хотя бы один paid Payment, но ни один не покрывает ref_number.
        $notRenewedSql = "
            SELECT
                p.user_id          AS user_id,
                p.course_id        AS course_id,
                ref.ref_number     AS ref_block_number,
                ?                  AS debt_type,
                MAX(p.id)          AS last_payment_id
            FROM payments p
            INNER JOIN ({$refSql}) AS ref ON ref.course_id = p.course_id
            WHERE p.status IN ({$paidIn})
            GROUP BY p.user_id, p.course_id, ref.ref_number
            HAVING SUM(CASE WHEN (
                    (p.start_block IS NULL AND p.end_block IS NULL)
                    OR (p.start_block <= ref.ref_number AND p.end_block >= ref.ref_number)
                    OR (p.start_block <= ref.ref_number AND p.end_block IS NULL)
                    OR (p.start_block IS NULL AND p.end_block >= ref.ref_number)
                ) THEN 1 ELSE 0 END) = 0
        ";

        // B: no_payment — юзер в course-группе, но paid Payment отсутствует.
        $noPaymentSql = "
            SELECT
                gu.user_id                                  AS user_id,
                cg.course_id                                AS course_id,
                ref.ref_number                              AS ref_block_number,
                ?                                           AS debt_type,
                CAST(NULL AS {$nullIntCast})                AS last_payment_id
            FROM group_user gu
            INNER JOIN course_group cg ON cg.group_id = gu.group_id
            INNER JOIN ({$refSql}) AS ref ON ref.course_id = cg.course_id
            WHERE NOT EXISTS (
                SELECT 1 FROM payments p2
                WHERE p2.user_id = gu.user_id
                  AND p2.course_id = cg.course_id
                  AND p2.status IN ({$paidIn})
            )
            GROUP BY gu.user_id, cg.course_id, ref.ref_number
        ";

        $bindings = array_merge(
            ['not_renewed'],
            $refBindings,
            self::PAID_STATUSES,
            ['no_payment'],
            $refBindings,
            self::PAID_STATUSES,
        );

        return [$notRenewedSql.' UNION ALL '.$noPaymentSql, $bindings];
    }

    /**
     * Сумма долга по списку неоплаченных блоков курса.
     * Использует `Tariff::calculateFinalPriceForUser()` (учёт loyalty-скидки).
     *
     * Алгоритм:
     *   1) Для каждого номера блока ищется точный block-тариф.
     *   2) Если его нет — используется fallback-цена: средняя по существующим
     *      block-тарифам курса; если их нет — full-тариф / число блоков курса.
     *   3) Каждый блок, оценённый по fallback'у, считается «приблизительным» —
     *      `missing_tariffs` инкрементируется, сумма помечается «≈» в UI.
     *
     * @param  list<int>  $debtBlockNumbers
     * @return array{amount: ?float, missing_tariffs: int}
     */
    public function computeDebtAmount(User $user, int $courseId, array $debtBlockNumbers): array
    {
        if (empty($debtBlockNumbers)) {
            return ['amount' => null, 'missing_tariffs' => 0];
        }

        $tariffs = $this->blockTariffsFor($courseId);
        $fallbackPrice = $this->fallbackBlockPriceFor($courseId, $user, $tariffs);

        $amount = 0.0;
        $found = 0;
        $missing = 0;
        foreach ($debtBlockNumbers as $n) {
            $tariff = $tariffs[$n] ?? null;
            if ($tariff !== null) {
                $amount += (float) $tariff->calculateFinalPriceForUser($user);
                $found++;

                continue;
            }
            if ($fallbackPrice !== null) {
                $amount += $fallbackPrice;
            }
            $missing++;
        }

        $hasAnyValue = $found > 0 || ($missing > 0 && $fallbackPrice !== null);

        return [
            'amount' => $hasAnyValue ? $amount : null,
            'missing_tariffs' => $missing,
        ];
    }

    /**
     * Оценка цены за блок, когда точного block-тарифа нет:
     *   - среднее по существующим block-тарифам курса (если есть);
     *   - иначе full-тариф курса делённый на число CourseBlock'ов курса.
     *
     * @param  array<int, Tariff>  $blockTariffs
     */
    private function fallbackBlockPriceFor(int $courseId, User $user, array $blockTariffs): ?float
    {
        if (! empty($blockTariffs)) {
            $prices = array_map(
                fn (Tariff $t) => (float) $t->calculateFinalPriceForUser($user),
                $blockTariffs,
            );

            return array_sum($prices) / count($prices);
        }

        $fullTariff = Tariff::query()
            ->where('course_id', $courseId)
            ->where('type', 'full')
            ->where('is_active', true)
            ->first();
        if (! $fullTariff) {
            return null;
        }

        $totalBlocks = CourseBlock::query()->where('course_id', $courseId)->count();
        if ($totalBlocks <= 0) {
            return null;
        }

        return (float) $fullTariff->calculateFinalPriceForUser($user) / $totalBlocks;
    }

    /**
     * Один активный/просроченный promise для пары — кэшируется.
     * Заполняет кэш сразу для всей коллекции пар, чтобы избежать N+1.
     *
     * @param  iterable<array{user_id:int, course_id:int}>  $pairs
     */
    public function preloadPromises(iterable $pairs): void
    {
        $userIds = [];
        $courseIds = [];
        foreach ($pairs as $p) {
            $userIds[] = $p['user_id'];
            $courseIds[] = $p['course_id'];
        }
        if (empty($userIds)) {
            return;
        }

        $rows = PaymentPromise::query()
            ->whereIn('user_id', array_unique($userIds))
            ->whereIn('course_id', array_unique($courseIds))
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->orderByDesc('promised_at')
            ->get();

        foreach ($rows as $row) {
            $key = $row->user_id.':'.$row->course_id;
            if (! isset($this->promiseCache[$key])) {
                $this->promiseCache[$key] = $row;
            }
        }
    }

    public function promiseFor(int $userId, int $courseId): ?PaymentPromise
    {
        $key = $userId.':'.$courseId;
        if (array_key_exists($key, $this->promiseCache)) {
            return $this->promiseCache[$key];
        }

        $row = PaymentPromise::query()
            ->forPair($userId, $courseId)
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->orderByDesc('promised_at')
            ->first();

        return $this->promiseCache[$key] = $row;
    }

    /**
     * Карта block_number → Tariff (type='block', is_active=true) для курса.
     *
     * @return array<int, Tariff>
     */
    private function blockTariffsFor(int $courseId): array
    {
        if (isset($this->blockTariffsCache[$courseId])) {
            return $this->blockTariffsCache[$courseId];
        }

        $tariffs = Tariff::query()
            ->where('course_id', $courseId)
            ->where('type', 'block')
            ->where('is_active', true)
            ->whereNotNull('block_number')
            ->get();

        $map = [];
        foreach ($tariffs as $t) {
            $map[(int) $t->block_number] = $t;
        }

        return $this->blockTariffsCache[$courseId] = $map;
    }

    /**
     * Покрывает ли paid Payment с границами (start, end) конкретный номер блока.
     * NULL-границы трактуются как «открытая сторона» — частный случай
     * (NULL,NULL) = «весь курс» (legacy/full-платежи).
     */
    public static function paymentCovers(?int $start, ?int $end, int $n): bool
    {
        if ($start === null && $end === null) {
            return true;
        }
        if ($start !== null && $end !== null) {
            return $start <= $n && $end >= $n;
        }
        if ($start !== null) {
            return $start <= $n;
        }

        return $end >= $n;
    }

    /**
     * Сжимает упорядоченный список номеров блоков в диапазоны:
     *   [1,2,3,5,7,8] → "№1–3, №5, №7–8".
     *
     * @param  list<int>  $numbers
     */
    public static function formatBlockRanges(array $numbers): string
    {
        if (empty($numbers)) {
            return '—';
        }

        sort($numbers);
        $ranges = [];
        $start = $numbers[0];
        $prev = $start;
        $count = count($numbers);
        for ($i = 1; $i < $count; $i++) {
            if ($numbers[$i] === $prev + 1) {
                $prev = $numbers[$i];

                continue;
            }
            $ranges[] = $start === $prev ? "№{$start}" : "№{$start}–{$prev}";
            $start = $numbers[$i];
            $prev = $start;
        }
        $ranges[] = $start === $prev ? "№{$start}" : "№{$start}–{$prev}";

        return implode(', ', $ranges);
    }

    /**
     * Inline-SQL и bindings для derived-таблицы reference-блоков.
     *   SELECT ? AS course_id, ? AS ref_number UNION ALL SELECT ?, ? ...
     *
     * @param  Collection<int, CourseBlock>  $refs
     * @return array{0: string, 1: array<int, int>}
     */
    private function buildReferenceInlineSql(Collection $refs): array
    {
        $parts = [];
        $bindings = [];
        $first = true;
        foreach ($refs as $courseId => $block) {
            $parts[] = $first
                ? 'SELECT ? AS course_id, ? AS ref_number'
                : 'SELECT ?, ?';
            $bindings[] = (int) $courseId;
            $bindings[] = (int) $block->number;
            $first = false;
        }

        return [implode(' UNION ALL ', $parts), $bindings];
    }
}
