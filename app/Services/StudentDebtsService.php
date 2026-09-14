<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudentDebtsService
{
    private const PAID_STATUSES = Payment::PAID_STATUSES;

    /**
     * Долги конкретного студента. В выборку попадают курсы, по которым:
     *   1) был хотя бы один реальный (не conditional) успешный платёж, но
     *      текущий блок им не покрыт — классический «не продлил»; либо
     *   2) есть непогашенная договорённость — активное или просроченное
     *      обещание оплаты / план рассрочки (доступ часто открыт «в кредит»
     *      conditional-платежами, реальной оплаты ещё не было).
     *
     * Бесплатный доступ через одно лишь групповое назначение (без платежа и
     * без обещания) задолженностью не считается.
     *
     * Возвращает коллекцию объектов вида:
     *   {course, course_id, ref_block, debt_block_numbers, debt_label,
     *    debt_amount, promise, promises (график), is_installment, ...}
     */
    public function forUser(User $user): Collection
    {
        $realPaidCourseIds = Payment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', self::PAID_STATUSES)
            ->where('is_conditional', false)
            ->pluck('course_id')
            ->unique()
            ->values();

        // Курсы с непогашенной договорённостью: активные + просроченные
        // обещания (expired ставит демон promises:expire). Выполненные и
        // отменённые долг не образуют.
        $arrangementCourseIds = PaymentPromise::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [PaymentPromise::STATUS_ACTIVE, PaymentPromise::STATUS_EXPIRED])
            ->pluck('course_id')
            ->unique()
            ->values();

        $candidateIds = $realPaidCourseIds->merge($arrangementCourseIds)->unique()->values();
        if ($candidateIds->isEmpty()) {
            return collect();
        }

        $courses = Course::query()
            ->whereIn('id', $candidateIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($courses->isEmpty()) {
            return collect();
        }

        $blocksByCourse = CourseBlock::query()
            ->whereIn('course_id', $courses->keys())
            ->orderBy('course_id')
            ->orderBy('number')
            ->get()
            ->groupBy('course_id');

        $paymentsByCourse = Payment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', self::PAID_STATUSES)
            ->where('is_conditional', false)
            ->whereIn('course_id', $courses->keys())
            ->get(['course_id', 'start_block', 'end_block'])
            ->groupBy('course_id');

        // Строка course_user по каждому курсу: joined_at_block — приоритетная
        // нижняя граница долга (если не задан, debtFloor берёт первый оплаченный
        // блок), status — гейт «ушедшим долг не начисляем», left_after_block —
        // потолок долга («Блок выхода» в админке).
        $pivotByCourse = DB::table('course_user')
            ->where('user_id', $user->id)
            ->whereIn('course_id', $courses->keys())
            ->get(['course_id', 'status', 'joined_at_block', 'left_after_block'])
            ->keyBy('course_id');

        // Полный график договорённостей: активные/просроченные/выполненные —
        // выполненные нужны, чтобы показать рассрочку целиком («2 из 4 внесено»).
        // Отменённые исключаем. Сортировка по дате = порядок графика платежей.
        $promisesByCourse = PaymentPromise::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $courses->keys())
            ->whereIn('status', [
                PaymentPromise::STATUS_ACTIVE,
                PaymentPromise::STATUS_EXPIRED,
                PaymentPromise::STATUS_FULFILLED,
            ])
            ->orderBy('promised_at')
            ->get()
            ->groupBy('course_id');

        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $report = app(DebtorsReport::class);

        $result = collect();
        foreach ($courses as $courseId => $course) {
            $courseId = (int) $courseId;
            $pivot = $pivotByCourse->get($courseId);

            // Ушедшие/исключённые/льготники/выпускники должниками не считаются —
            // зеркально админскому Debtors и debts:remind (та же константа).
            if ($pivot !== null && in_array((string) $pivot->status, DebtorsReport::NON_DEBT_STATUSES, true)) {
                continue;
            }

            $blocks = $blocksByCourse->get($courseId, collect());
            $payments = $paymentsByCourse->get($courseId, collect());
            $promises = $promisesByCourse->get($courseId, collect());

            // Непогашенные обещания (ждём денег) — активные + просроченные.
            $unmet = $promises->filter(fn (PaymentPromise $p) => $p->isUnmet());
            $hasArrangement = $unmet->isNotEmpty();

            $refBlock = $this->referenceBlock($blocks, $now, $today);

            // Без reference-блока «не продлил» посчитать нельзя (курс без
            // актуального блока — например завершённый поток без дат). Но
            // непогашенная договорённость (рассрочка/обещание) — это всё равно
            // долг: показываем по графику платежей, без привязки к блоку.
            if (! $refBlock instanceof CourseBlock && ! $hasArrangement) {
                continue;
            }

            $refNumber = $refBlock instanceof CourseBlock ? (int) $refBlock->number : null;

            // «Блок выхода» — потолок долга: блоки после него не начисляются
            // (обещание хелпер-текста поля в админке). Если курс уже ушёл дальше
            // блока выхода, «текущим» для долга считается блок выхода.
            $leftAfter = $pivot?->left_after_block !== null ? (int) $pivot->left_after_block : null;
            if ($refNumber !== null && $leftAfter !== null) {
                $refNumber = min($refNumber, $leftAfter);
            }

            // Текущий блок покрыт реальной (не conditional) оплатой?
            $refCovered = false;
            $debtNumbers = [];
            if ($refNumber !== null) {
                foreach ($payments as $p) {
                    if (DebtorsReport::paymentCovers($p->start_block, $p->end_block, $refNumber)) {
                        $refCovered = true;
                        break;
                    }
                }
                $explicitJoined = $pivot?->joined_at_block !== null ? (int) $pivot->joined_at_block : null;
                $debtNumbers = $this->debtBlockNumbers($blocks, $refNumber, $payments, $explicitJoined);
            }

            // Без договорённости курс — долг только по «не продлил»-логике:
            // текущий блок не покрыт и есть неоплаченные блоки. С активной
            // договорённостью показываем всегда (график / предстоит / просрочка).
            if (! $hasArrangement && ($refCovered || empty($debtNumbers))) {
                continue;
            }

            $debtAmount = $report->computeDebtAmount($user, $courseId, $debtNumbers);

            $overdue = $unmet->filter(fn (PaymentPromise $p) => $p->isOverdueOrExpired())
                ->sortBy('promised_at')
                ->values();
            $upcoming = $unmet->reject(fn (PaymentPromise $p) => $p->isOverdueOrExpired())
                ->sortBy('promised_at')
                ->values();

            $isInstallment = $promises->contains(fn (PaymentPromise $p) => $p->installment_group_id !== null)
                || $promises->count() > 1;

            // Сумма, которую ещё предстоит внести по графику (неоплаченные обещания).
            $planRemaining = (float) $unmet->sum(fn (PaymentPromise $p) => (float) ($p->amount ?? 0));
            $planTotal = (float) $promises->sum(fn (PaymentPromise $p) => (float) ($p->amount ?? 0));

            // «Главное» обещание для компактного баннера: первое просроченное,
            // иначе ближайшее предстоящее, иначе последнее в графике.
            $primary = $overdue->first() ?? $upcoming->first() ?? $promises->last();

            $result->push((object) [
                'course' => $course,
                'course_id' => $courseId,
                'ref_block' => $refBlock,
                'debt_block_numbers' => $debtNumbers,
                'debt_label' => DebtorsReport::formatBlockRanges($debtNumbers),
                'debt_amount' => $debtAmount['amount'],
                'debt_amount_approximate' => $debtAmount['missing_tariffs'] > 0,
                'has_real_payment' => $realPaidCourseIds->contains($courseId),
                'has_arrangement' => $hasArrangement,
                'promise' => $primary,
                'promise_active' => $hasArrangement && $overdue->isEmpty() && $upcoming->isNotEmpty(),
                'promise_overdue' => $overdue->isNotEmpty(),
                'promises' => $promises,
                'is_installment' => $isInstallment,
                'plan_total' => $planTotal > 0 ? $planTotal : null,
                'plan_remaining' => $planRemaining > 0 ? $planRemaining : null,
                'overdue_count' => $overdue->count(),
            ]);
        }

        return $result;
    }

    /**
     * «Оплачено до» по каждому курсу из списка: последний блок, покрытый
     * непрерывной цепочкой реальных (не conditional) оплат начиная с блока
     * входа студента (joined_at_block / первый оплаченный блок). Курсы без
     * единого оплаченного блока в выборку не попадают — там показывать
     * «оплачено до» нечего, это чистый долг.
     *
     * Считается независимо от reference-блока: если студент заплатил вперёд
     * (несколько блоков разом), «оплачено до» уйдёт дальше текущего блока.
     *
     * Заодно отдаёт дедлайн следующего платежа: первый непокрытый блок сразу
     * после «оплачено до» и момент его старта (00:00 по Москве — приложение
     * работает в Europe/Moscow, см. config('app.timezone'), так что starts_at
     * уже в нужном поясе без ручной конвертации). Это правило дедлайна задал
     * MG: «до дня старта следующего модуля, до 00:00 по Москве». Если
     * следующего блока в расписании ещё нет (курс оплачен до конца известных
     * блоков) — next_block/next_payment_deadline будут null, платить пока
     * нечего.
     *
     * Разрыв цепочки не обрывает подсчёт: если студент пропустил блок и
     * вернулся дальше («пропустил 64 — оплатил 65»), непрерывная цепочка
     * честно останавливается на 63, а отдельно оплаченные блоки после
     * разрыва собираются в extra_paid_blocks (+ готовый extra_paid_blocks_label),
     * чтобы сводки не делали вид, что этих оплат нет.
     *
     * Возвращает коллекцию объектов {course_id, block (CourseBlock),
     * amount_paid, next_block (?CourseBlock), next_payment_deadline (?Carbon),
     * extra_paid_blocks (list<int>), extra_paid_blocks_label (?string)},
     * ключ — course_id.
     *
     * @param  iterable<int>  $courseIds
     * @return Collection<int, object>
     */
    public function paidUntilForUser(User $user, iterable $courseIds): Collection
    {
        $courseIds = collect($courseIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($courseIds->isEmpty()) {
            return collect();
        }

        $blocksByCourse = CourseBlock::query()
            ->whereIn('course_id', $courseIds)
            ->orderBy('course_id')
            ->orderBy('number')
            ->get()
            ->groupBy('course_id');

        $paymentsByCourse = Payment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', self::PAID_STATUSES)
            ->where('is_conditional', false)
            ->whereIn('course_id', $courseIds)
            ->get(['course_id', 'start_block', 'end_block', 'amount'])
            ->groupBy('course_id');

        $joinedByCourse = DB::table('course_user')
            ->where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('joined_at_block')
            ->pluck('joined_at_block', 'course_id');

        $result = collect();
        foreach ($courseIds as $courseId) {
            $blocks = $blocksByCourse->get($courseId, collect());
            $payments = $paymentsByCourse->get($courseId, collect());
            if ($blocks->isEmpty() || $payments->isEmpty()) {
                continue;
            }

            $explicitJoined = $joinedByCourse->get($courseId);
            $explicitJoined = $explicitJoined !== null ? (int) $explicitJoined : null;
            $floor = DebtorsReport::debtFloor($explicitJoined, $payments);

            $lastCovered = null;
            $nextBlock = null;
            // Отдельно оплаченные блоки ПОСЛЕ первого разрыва цепочки.
            $extraPaidBlocks = [];
            foreach ($blocks as $block) {
                $n = (int) $block->number;
                if ($floor !== null && $n < $floor) {
                    continue;
                }
                $covered = false;
                foreach ($payments as $p) {
                    if (DebtorsReport::paymentCovers($p->start_block, $p->end_block, $n)) {
                        $covered = true;
                        break;
                    }
                }
                if (! $covered) {
                    // Первый разрыв — дальше цепочка не непрерывна. Этот блок
                    // и есть «следующий», за который нужно платить; но идём
                    // дальше — оплаты после разрыва собираем в extra_paid_blocks.
                    if ($nextBlock === null) {
                        $nextBlock = $block;
                    }

                    continue;
                }
                if ($nextBlock === null) {
                    $lastCovered = $block;
                } else {
                    $extraPaidBlocks[] = $n;
                }
            }

            if ($lastCovered === null) {
                continue;
            }

            $result->push((object) [
                'course_id' => $courseId,
                'block' => $lastCovered,
                'amount_paid' => (float) $payments->sum('amount'),
                'next_block' => $nextBlock,
                'next_payment_deadline' => $nextBlock?->starts_at?->copy()->startOfDay(),
                'extra_paid_blocks' => $extraPaidBlocks,
                'extra_paid_blocks_label' => $extraPaidBlocks === []
                    ? null
                    : DebtorsReport::formatBlockRanges($extraPaidBlocks),
            ]);
        }

        return $result->keyBy('course_id');
    }

    /**
     * @param  Collection<int, CourseBlock>  $blocks
     */
    private function referenceBlock(Collection $blocks, Carbon $now, Carbon $today): ?CourseBlock
    {
        $manual = $blocks->firstWhere('is_current', true);
        if ($manual !== null) {
            return $manual;
        }

        $byDate = $blocks->first(fn (CourseBlock $b) => $b->starts_at !== null
            && $b->ends_at !== null
            && $b->starts_at->lte($now)
            && $b->ends_at->gte($today)
        );
        if ($byDate !== null) {
            return $byDate;
        }

        return $blocks
            ->filter(fn (CourseBlock $b) => $b->starts_at !== null && $b->starts_at->gt($now))
            ->sortBy('starts_at')
            ->first();
    }

    /**
     * Неоплаченные блоки курса по возрастанию — БЕЗ потолка reference-блока (в
     * отличие от debtBlockNumbers, где потолок = текущий блок). Нужен
     * DebtPaymentController, чтобы сопоставить взнос рассрочки с конкретным блоком
     * даже когда у курса нет актуального reference-блока (тогда debt_block_numbers
     * пуст и ключ ошибочно падал в 'full'). Нижняя граница (блок входа) — та же.
     *
     * @return list<int>
     */
    public function unpaidBlockNumbers(User $user, int $courseId): array
    {
        $blocks = CourseBlock::query()
            ->where('course_id', $courseId)
            ->orderBy('number')
            ->get();

        if ($blocks->isEmpty()) {
            return [];
        }

        $payments = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->whereIn('status', self::PAID_STATUSES)
            ->where('is_conditional', false)
            ->get(['start_block', 'end_block']);

        $explicitJoined = DB::table('course_user')
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->whereNotNull('joined_at_block')
            ->value('joined_at_block');
        $explicitJoined = $explicitJoined !== null ? (int) $explicitJoined : null;

        $floor = DebtorsReport::debtFloor($explicitJoined, $payments);

        $debt = [];
        foreach ($blocks as $block) {
            $n = (int) $block->number;
            if ($floor !== null && $n < $floor) {
                continue;
            }
            $covered = false;
            foreach ($payments as $p) {
                if (DebtorsReport::paymentCovers($p->start_block, $p->end_block, $n)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $debt[] = $n;
            }
        }

        return $debt;
    }

    /**
     * @param  Collection<int, CourseBlock>  $blocks
     * @param  Collection<int, Payment>  $payments
     * @return list<int>
     */
    private function debtBlockNumbers(Collection $blocks, int $refNumber, Collection $payments, ?int $explicitJoined = null): array
    {
        // Нижняя граница: блоки до «блока входа» студента (присоединился к
        // потоку в середине) в долг не идут. См. DebtorsReport::debtFloor.
        $floor = DebtorsReport::debtFloor($explicitJoined, $payments);

        $debt = [];
        foreach ($blocks as $block) {
            $n = (int) $block->number;
            if ($n > $refNumber) {
                continue;
            }
            if ($floor !== null && $n < $floor) {
                continue;
            }
            $covered = false;
            foreach ($payments as $p) {
                if (DebtorsReport::paymentCovers($p->start_block, $p->end_block, $n)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $debt[] = $n;
            }
        }

        return $debt;
    }
}
