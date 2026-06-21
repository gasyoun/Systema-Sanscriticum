<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\MarketingSetting;
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

    /**
     * Статусы в course_user, при которых пара (user, course) НЕ должна
     * попадать в список должников: студент либо завершил курс, либо вышел
     * из него, либо учится бесплатно (льготник). Их можно ставить как
     * вручную в карточке студента, так и через массовый импорт.
     */
    private const NON_DEBT_STATUSES = ['Покинул', 'Исключен', 'Льготник', 'Выпускник'];

    /**
     * Выбранные годы для «линзы» отчёта (год = год даты блока, starts_at).
     * Пустой массив = все годы (поведение по умолчанию, как было раньше).
     *
     * @var list<int>
     */
    private array $years = [];

    /** @var Collection<int, CourseBlock>|null  course_id => reference CourseBlock */
    private ?Collection $referenceBlocksCache = null;

    /** @var array<int, array<int, Tariff>>  course_id => [block_number => block-Tariff] */
    private array $blockTariffsCache = [];

    /** @var array<int, array{tariff: Tariff, blocks: int}|null>  course_id => fallback-база (full-тариф + число блоков) */
    private array $fallbackBaseCache = [];

    /** @var array<string, PaymentPromise|null>  "user_id:course_id" => активный/просроченный promise */
    private array $promiseCache = [];

    /** @var array<string, float>  "user_id:course_id" => сумма неизрасходованных депозитов */
    private array $depositCreditCache = [];

    /** @var array<int, int>  user_id => discount percent (0..100) */
    private array $discountPercentCache = [];

    /** @var array<int, User>  user_id => User (batch-preloaded для totalDebtForQuery) */
    private array $usersByIdCache = [];

    /**
     * Включает «линзу» по годам: долги определяются только за указанные годы
     * (год = год даты блока, course_blocks.starts_at). Пустой массив = все годы.
     * Сбрасывает зависящие от линзы кэши. Fluent.
     *
     * @param  array<int|string>  $years
     */
    public function forYears(array $years): self
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn ($y) => (int) $y, $years),
            static fn (int $y) => $y > 0,
        )));

        if ($normalized === $this->years) {
            return $this;
        }

        $this->years = $normalized;
        $this->referenceBlocksCache = null;

        return $this;
    }

    /**
     * Текущая выборка годов линзы (пусто = все годы).
     *
     * @return list<int>
     */
    public function years(): array
    {
        return $this->years;
    }

    /**
     * Глобально настроенный год для «Должников» (тумблеры на странице пишут его в
     * MarketingSetting.debtors_notify_years). Единая точка чтения: страница
     * восстанавливает выбор на mount, а будущая авто-рассылка должникам берёт
     * год отсюда — `forYears(self::configuredYears())`. Пусто = все годы.
     *
     * @return list<int>
     */
    public static function configuredYears(): array
    {
        $years = MarketingSetting::cached()?->debtors_notify_years ?? [];
        if (! is_array($years)) {
            return [];
        }

        $norm = array_values(array_unique(array_filter(
            array_map(static fn ($y) => (int) $y, $years),
            static fn (int $y) => $y > 0,
        )));
        sort($norm);

        return $norm;
    }

    /**
     * Доступные для выбора годы — distinct из дат блоков активных курсов,
     * по убыванию. Используется для рендера переключателей на странице.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $courseIds = Course::query()->where('is_active', true)->pluck('id');

        return CourseBlock::query()
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('starts_at')
            ->get(['starts_at'])
            ->map(fn (CourseBlock $b) => (int) $b->starts_at->year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * Reference-блок (потолок долга) для каждого активного курса.
     *
     * Основная логика — последний уже НАЧАВШИЙСЯ датированный блок
     * (`starts_at <= now`) в нужном диапазоне лет:
     *   - активная год-линза ($this->years непусто) → блок с годом ∈ выбранным;
     *   - пустая выборка («Все годы») → блок любого года = объединение всех лет.
     * Так SQL-гейт «кто должник» считается за нужный год без правки самого SQL.
     *
     * Fallback ТОЛЬКО для пустой выборки и курсов без начавшегося датированного
     * блока (курс без дат / ещё не стартовал), чтобы не терять по ним должников:
     * 1) `is_current=true`; 2) текущий по датам; 3) ближайший предстоящий.
     *
     * Блоки без даты по году классифицировать нельзя → при активной год-линзе
     * такие курсы выпадают (по пустой выборке — подхватываются fallback’ом).
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
            // Reference по году: последний уже начавшийся блок с датой в нужном
            // диапазоне. При активной линзе диапазон = выбранные годы; при пустой
            // выборке («Все годы») диапазон не ограничен — берётся последний
            // начавшийся датированный блок любого года (объединение всех лет).
            $scoped = $courseBlocks
                ->filter(fn (CourseBlock $b) => $b->starts_at !== null
                    && $b->starts_at->lte($now)
                    && (empty($this->years) || in_array((int) $b->starts_at->year, $this->years, true))
                )
                ->sortByDesc('starts_at')
                ->first();
            if ($scoped !== null) {
                $result->put((int) $courseId, $scoped);

                continue;
            }

            // Явно выбран год, но у курса нет начавшегося блока этого года — пропуск.
            if (! empty($this->years)) {
                continue;
            }

            // Пустая выборка + у курса нет ни одного начавшегося датированного
            // блока (курс без дат / ещё не стартовал) → legacy-логика, чтобы не
            // потерять должников по таким курсам: ручной флаг → текущий по датам
            // → ближайший предстоящий.
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
                'd.course_user_status',
                'd.course_user_left_after_block',
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
     * @return array<int, string> course_id => title
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

        // course_user-фильтр: исключаем пары с «терминальными» статусами
        // (Покинул/Исключен/Льготник/Выпускник). Льготник учится бесплатно,
        // остальные три — фактически больше не учатся. Если в course_user
        // вообще нет записи (NULL после LEFT JOIN) — пара остаётся в долге.
        $nonDebtIn = implode(',', array_fill(0, count(self::NON_DEBT_STATUSES), '?'));

        // A: not_renewed — есть хотя бы один paid Payment, но ни один не покрывает ref_number.
        // Conditional платежи (доступ под обещание) НЕ считаются «настоящей» оплатой —
        // иначе должник исчезнет из списка сразу после открытия доступа в кредит.
        $notRenewedSql = "
            SELECT
                p.user_id          AS user_id,
                p.course_id        AS course_id,
                ref.ref_number     AS ref_block_number,
                ?                  AS debt_type,
                MAX(p.id)          AS last_payment_id,
                MAX(cu.status)     AS course_user_status,
                MAX(cu.left_after_block) AS course_user_left_after_block
            FROM payments p
            INNER JOIN ({$refSql}) AS ref ON ref.course_id = p.course_id
            LEFT JOIN course_user cu ON cu.user_id = p.user_id AND cu.course_id = p.course_id
            WHERE p.status IN ({$paidIn})
              AND p.is_conditional = 0
              AND p.tariff NOT IN ('Расход', 'salary_payout')
              AND (cu.status IS NULL OR cu.status NOT IN ({$nonDebtIn}))
            GROUP BY p.user_id, p.course_id, ref.ref_number
            HAVING SUM(CASE WHEN (
                    (p.start_block IS NULL AND p.end_block IS NULL)
                    OR (p.start_block <= ref.ref_number AND p.end_block >= ref.ref_number)
                    OR (p.start_block <= ref.ref_number AND p.end_block IS NULL)
                    OR (p.start_block IS NULL AND p.end_block >= ref.ref_number)
                ) THEN 1 ELSE 0 END) = 0
        ";

        // B: no_payment — юзер в course-группе, но реальный paid Payment отсутствует
        // (conditional платежи не учитываем).
        $noPaymentSql = "
            SELECT
                gu.user_id                                  AS user_id,
                cg.course_id                                AS course_id,
                ref.ref_number                              AS ref_block_number,
                ?                                           AS debt_type,
                CAST(NULL AS {$nullIntCast})                AS last_payment_id,
                MAX(cu.status)                              AS course_user_status,
                MAX(cu.left_after_block)                    AS course_user_left_after_block
            FROM group_user gu
            INNER JOIN course_group cg ON cg.group_id = gu.group_id
            INNER JOIN ({$refSql}) AS ref ON ref.course_id = cg.course_id
            LEFT JOIN course_user cu ON cu.user_id = gu.user_id AND cu.course_id = cg.course_id
            WHERE NOT EXISTS (
                SELECT 1 FROM payments p2
                WHERE p2.user_id = gu.user_id
                  AND p2.course_id = cg.course_id
                  AND p2.status IN ({$paidIn})
                  AND p2.is_conditional = 0
                  AND p2.tariff NOT IN ('Расход', 'salary_payout')
            )
              AND (cu.status IS NULL OR cu.status NOT IN ({$nonDebtIn}))
            GROUP BY gu.user_id, cg.course_id, ref.ref_number
        ";

        $bindings = array_merge(
            ['not_renewed'],
            $refBindings,
            self::PAID_STATUSES,
            self::NON_DEBT_STATUSES,
            ['no_payment'],
            $refBindings,
            self::PAID_STATUSES,
            self::NON_DEBT_STATUSES,
        );

        return [$notRenewedSql.' UNION ALL '.$noPaymentSql, $bindings];
    }

    /**
     * Сумма долга по списку неоплаченных блоков курса.
     *
     * Алгоритм:
     *   1) Для каждого номера блока ищется точный block-тариф; считается цена
     *      С УЧЁТОМ loyalty-скидки (но БЕЗ зачёта депозита).
     *   2) Если block-тарифа нет — используется fallback-цена: средняя по
     *      существующим block-тарифам курса; если их нет — full-тариф / число
     *      CourseBlock'ов. Каждый блок без точного тарифа отмечается в
     *      `missing_tariffs` (UI показывает «≈»).
     *   3) Депозит (если есть) — зачитывается ОДИН РАЗ против итоговой суммы.
     *      Раньше депозит вычитался из цены каждого блока по отдельности, и
     *      депозит в 5000 при долге за 3 блока по 2000 «съедал» долг полностью
     *      (3 × max(0, 2000 − 5000) = 0), занижая отчёт должников.
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

        $gross = 0.0;
        $found = 0;
        $missing = 0;
        foreach ($debtBlockNumbers as $n) {
            $tariff = $tariffs[$n] ?? null;
            if ($tariff !== null) {
                $gross += $this->priceWithLoyaltyForUser($tariff, $user);
                $found++;

                continue;
            }
            if ($fallbackPrice !== null) {
                $gross += $fallbackPrice;
            }
            $missing++;
        }

        $hasAnyValue = $found > 0 || ($missing > 0 && $fallbackPrice !== null);
        if (! $hasAnyValue) {
            return ['amount' => null, 'missing_tariffs' => $missing];
        }

        // Депозит зачитывается одним блоком против итога — это разовая
        // предоплата, а не скидка на каждую позицию.
        $depositCredit = $this->depositCreditFor((int) $user->id, $courseId);

        return [
            'amount' => max(0.0, $gross - $depositCredit),
            'missing_tariffs' => $missing,
        ];
    }

    /**
     * Цена тарифа с учётом ТОЛЬКО loyalty-скидки. Сознательно НЕ вычитает
     * депозит — депозит применяется один раз к итоговой сумме долга в
     * `computeDebtAmount`, а здесь функцию вызывают и для подсчёта средней
     * (`fallbackBlockPriceFor`), и для каждого блока. Применять депозит
     * на этом уровне = вычитать его N раз.
     */
    private function priceWithLoyaltyForUser(Tariff $tariff, User $user): float
    {
        $finalPrice = (float) $tariff->price;

        $discountPercent = $this->discountPercentForUser($user);
        if ($discountPercent > 0) {
            $finalPrice -= $finalPrice * ($discountPercent / 100);
        }

        return max(0.0, $finalPrice);
    }

    /**
     * Оценка цены за блок, когда точного block-тарифа нет:
     *   - среднее по существующим block-тарифам курса (если есть);
     *   - иначе full-тариф курса делённый на число CourseBlock'ов курса.
     *
     * Без зачёта депозита — депозит применяется один раз в computeDebtAmount.
     *
     * @param  array<int, Tariff>  $blockTariffs
     */
    private function fallbackBlockPriceFor(int $courseId, User $user, array $blockTariffs): ?float
    {
        if (! empty($blockTariffs)) {
            $prices = array_map(
                fn (Tariff $t) => $this->priceWithLoyaltyForUser($t, $user),
                $blockTariffs,
            );

            return array_sum($prices) / count($prices);
        }

        // full-тариф и число блоков зависят только от курса — кэшируем по
        // courseId. Иначе на сводке должников курсы без block-тарифов давали
        // по 2 запроса (тариф + count) на КАЖДУЮ пару (user, course).
        if (! array_key_exists($courseId, $this->fallbackBaseCache)) {
            $fullTariff = Tariff::query()
                ->where('course_id', $courseId)
                ->where('type', 'full')
                ->where('is_active', true)
                ->first();

            $totalBlocks = $fullTariff
                ? CourseBlock::query()->where('course_id', $courseId)->count()
                : 0;

            $this->fallbackBaseCache[$courseId] = ($fullTariff && $totalBlocks > 0)
                ? ['tariff' => $fullTariff, 'blocks' => $totalBlocks]
                : null;
        }

        $base = $this->fallbackBaseCache[$courseId];
        if ($base === null) {
            return null;
        }

        return $this->priceWithLoyaltyForUser($base['tariff'], $user) / $base['blocks'];
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
     * Нижняя граница долга — номер блока, С которого студент в потоке.
     * Блоки до неё в долг не начисляются (присоединился в середине потока).
     *
     * Приоритет:
     *   1) явно заданный course_user.joined_at_block (ручной ввод / импорт);
     *   2) иначе — первый реально оплаченный блок (минимальный start_block
     *      среди не-conditional оплат; NULL-границы = «весь курс» игнорируем,
     *      такой платёж и так покрывает всё).
     * NULL = границы нет (студент с потока / данных недостаточно) → долг
     * считается с первого блока, как раньше.
     *
     * @param  iterable<object{start_block:?int}>  $payments  реальные paid-платежи пары
     */
    public static function debtFloor(?int $explicitJoinedBlock, iterable $payments): ?int
    {
        if ($explicitJoinedBlock !== null && $explicitJoinedBlock > 0) {
            return $explicitJoinedBlock;
        }

        $min = null;
        foreach ($payments as $p) {
            $start = $p->start_block;
            if ($start !== null && ($min === null || (int) $start < $min)) {
                $min = (int) $start;
            }
        }

        return $min;
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
     * Дни просрочки относительно начала reference-блока. Если now < starts_at
     * (блок ещё не начался — мы попали в список как «ближайший предстоящий»),
     * просрочки нет: возвращаем 0. Дата отсутствует → тоже 0.
     */
    public function daysOverdueFor(int $courseId, int $refBlockNumber): int
    {
        $block = $this->referenceBlocks()->get($courseId);
        if (! $block instanceof CourseBlock || ! $block->starts_at) {
            return 0;
        }

        $start = $block->starts_at instanceof Carbon
            ? $block->starts_at
            : Carbon::parse($block->starts_at);

        if ($start->isFuture()) {
            return 0;
        }

        return (int) $start->startOfDay()->diffInDays(Carbon::now()->startOfDay());
    }

    /**
     * Человекочитаемая просрочка: «просрочено 5 дн» / «просрочено 3 нед».
     * Граница недель — 14 дней (две полные недели), иначе цифра по дням
     * больше похожа на «дня три», но без склонения, поэтому оставляем сокращение.
     */
    public static function formatOverdue(int $days): string
    {
        if ($days <= 0) {
            return '';
        }

        if ($days >= 14) {
            $weeks = (int) floor($days / 7);

            return "просрочено {$weeks} нед";
        }

        return "просрочено {$days} дн";
    }

    /**
     * Тотал по результату Filament-запроса:
     *   amount  — сумма всех долгов
     *   missing — сколько пар имели хотя бы один блок «по средней цене»
     *   by_course — карта course_id → сумма
     *   pairs   — сколько пар (user, course) учтено
     *
     * Производительность: 200 пар × ~3 блока × ~4 SELECT (loyalty, deposits,
     * marketing_setting × N) — это легко 2000+ запросов. Поэтому:
     *   1) Users грузим одним whereIn вместо User::find в цикле.
     *   2) Loyalty-процент считаем один раз на user (а не на каждый блок).
     *   3) Сумму депозитов считаем один раз на пару (user, course).
     * Эти кэши лежат на самом объекте DebtorsReport — он создаётся свежий
     * на каждый HTTP-запрос, так что stale-данных между запросами не будет.
     *
     * @return array{amount: float, missing: int, by_course: array<int, float>, pairs: int}
     */
    public function totalDebtForQuery(Builder $query): array
    {
        $clone = (clone $query)->limit(5000);
        $rows = $clone->get();

        // Шаг 1. Batch-load всех юзеров одним whereIn (раньше N+1 через User::find).
        $userIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->unique()->all();
        if (! empty($userIds)) {
            $missing = array_diff($userIds, array_keys($this->usersByIdCache));
            if (! empty($missing)) {
                $fetched = User::query()->whereIn('id', $missing)->get();
                foreach ($fetched as $u) {
                    $this->usersByIdCache[(int) $u->id] = $u;
                }
            }
        }

        // Шаг 2. Предсосчитать loyalty-процент один раз на user.
        foreach ($userIds as $uid) {
            $this->discountPercentForUser($this->usersByIdCache[$uid] ?? null);
        }

        // Шаг 3. Предсосчитать сумму депозитов одним запросом на все пары.
        $this->preloadDepositCredits($rows);

        // Шаг 4. Прогреть кэши платежей и joined_at_block для debtBlocks() —
        // двумя whereIn-запросами вместо 2×N+1 (по 2 запроса на каждую пару).
        \App\Filament\Pages\Debtors::preloadPairCaches($rows);

        $totalAmount = 0.0;
        $totalMissing = 0;
        $byCourse = [];
        $pairs = 0;

        foreach ($rows as $row) {
            $userId = (int) $row->id;
            $courseId = (int) $row->course_id;
            $refNumber = (int) $row->ref_block_number;

            $user = $this->usersByIdCache[$userId] ?? null;
            if (! $user) {
                continue;
            }

            $blocks = \App\Filament\Pages\Debtors::debtBlocks($userId, $courseId, $refNumber, $this->years);

            $info = $this->computeDebtAmount($user, $courseId, $blocks);
            $amount = (float) ($info['amount'] ?? 0);
            $totalAmount += $amount;
            if ($info['missing_tariffs'] > 0) {
                $totalMissing++;
            }
            $byCourse[$courseId] = ($byCourse[$courseId] ?? 0.0) + $amount;
            $pairs++;
        }

        return [
            'amount' => $totalAmount,
            'missing' => $totalMissing,
            'by_course' => $byCourse,
            'pairs' => $pairs,
        ];
    }

    /**
     * Memoized-обёртка над Tariff::getDiscountPercentForUser — считает один
     * раз на user_id внутри текущего DebtorsReport. Сама по себе процедура
     * включает SELECT по marketing_settings + GROUP BY по payments;
     * на странице должников эта пара запросов иначе бьётся сотни раз.
     */
    public function discountPercentForUser(?User $user): int
    {
        if (! $user) {
            return 0;
        }

        $key = (int) $user->id;
        if (array_key_exists($key, $this->discountPercentCache)) {
            return $this->discountPercentCache[$key];
        }

        // Используем «технический» Tariff для вызова метода — конкретный
        // course_id здесь не важен, метод смотрит только на user.
        $stub = new Tariff;
        $pct = $stub->getDiscountPercentForUser($user);

        return $this->discountPercentCache[$key] = (int) $pct;
    }

    /**
     * Сумма неизрасходованных депозитов user×course — кэшируется. Если
     * передан список пар, грузим их батчем одним SQL'ом (group by).
     *
     * @param  iterable<object{id:int|string, course_id:int|string}>  $rows
     */
    public function preloadDepositCredits(iterable $rows): void
    {
        $byUser = [];
        foreach ($rows as $r) {
            $uid = (int) $r->id;
            $cid = (int) $r->course_id;
            $byUser[$uid][$cid] = true;
        }
        if (empty($byUser)) {
            return;
        }

        $userIds = array_keys($byUser);
        $courseIds = array_unique(array_merge(...array_map('array_keys', array_values($byUser))));

        $sums = \App\Models\Payment::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('course_id', $courseIds)
            ->unconsumedDeposits()
            ->groupBy('user_id', 'course_id')
            ->selectRaw('user_id, course_id, SUM(amount) AS total')
            ->get();

        foreach ($sums as $s) {
            $key = ((int) $s->user_id).':'.((int) $s->course_id);
            $this->depositCreditCache[$key] = (float) $s->total;
        }

        // Записать 0 для пар, по которым депозитов нет, чтобы не лезть в БД повторно.
        foreach ($byUser as $uid => $courses) {
            foreach (array_keys($courses) as $cid) {
                $key = $uid.':'.$cid;
                if (! array_key_exists($key, $this->depositCreditCache)) {
                    $this->depositCreditCache[$key] = 0.0;
                }
            }
        }
    }

    public function depositCreditFor(int $userId, int $courseId): float
    {
        $key = $userId.':'.$courseId;
        if (array_key_exists($key, $this->depositCreditCache)) {
            return $this->depositCreditCache[$key];
        }

        $sum = (float) \App\Models\Payment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->unconsumedDeposits()
            ->sum('amount');

        return $this->depositCreditCache[$key] = $sum;
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
