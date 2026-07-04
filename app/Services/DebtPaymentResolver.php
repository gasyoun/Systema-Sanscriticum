<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentPromise;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Превращает строку долга из StudentDebtsService::forUser() в конкретные
 * «варианты оплаты» для кнопки «Оплатить» в кабинете студента (self-service).
 *
 * Ничего не создаёт и не пишет — только считает и маршрутизирует. Реальные
 * платежи создают штатный CheckoutController (плоский долг «не продлил») или
 * DebtPaymentController (согласованная рассрочка/обещание).
 *
 * Решения MG 04-07-2026 (docs/debtor-self-service-spec.md):
 *  - плоский долг → сопоставление с существующими тарифами (full / block_N),
 *    fallback на поблочные ссылки; ничего нового в платёжном пути;
 *  - рассрочка → внести следующий платёж по графику ИЛИ погасить всё сразу.
 */
class DebtPaymentResolver
{
    /**
     * @param  object  $debt  строка из StudentDebtsService::forUser()
     * @return array{
     *   type: 'arrangement'|'tariff'|'none',
     *   next?: array{amount: float|null, url: string, promise_id: int, overdue: bool}|null,
     *   whole?: array{amount: float|null, url: string}|null,
     *   installment?: bool,
     *   full?: array{title: string, url: string}|null,
     *   blocks?: list<array{number: int, title: string, url: string}>,
     *   unpriced_blocks?: list<int>,
     * }
     */
    public function optionsFor(object $debt, ?User $user = null): array
    {
        // Договорённость (обещание/рассрочка) — платим согласованную сумму, а не
        // цену тарифа: она часто со скидкой куратора. Это приоритетная ветка:
        // если есть непогашенное обещание, ведём студента по нему.
        if (! empty($debt->has_arrangement)) {
            return $this->arrangementOptions($debt);
        }

        // Долг «не продлил» без договорённости — штатный чекаут по тарифам.
        return $this->tariffOptions($debt, $user);
    }

    /**
     * @return array{type: 'arrangement', next: array|null, whole: array|null, installment: bool}
     */
    private function arrangementOptions(object $debt): array
    {
        /** @var Collection<int, PaymentPromise> $promises */
        $promises = $debt->promises ?? collect();

        $unmet = $promises
            ->filter(fn (PaymentPromise $p) => $p->isUnmet())
            ->sortBy('promised_at')
            ->values();

        $isInstallment = (bool) ($debt->is_installment ?? false);

        // «Следующий платёж» — ближайшее непогашенное обещание графика.
        $nextPromise = $unmet->first();
        $next = null;
        if ($nextPromise instanceof PaymentPromise) {
            $amount = $nextPromise->amount !== null
                ? (float) $nextPromise->amount
                : ($debt->debt_amount !== null ? (float) $debt->debt_amount : null);

            $next = [
                'amount' => $amount,
                'url' => route('student.debt.promise.pay', $nextPromise),
                'promise_id' => $nextPromise->id,
                'overdue' => $nextPromise->isOverdueOrExpired(),
            ];
        }

        // «Погасить всё» — суммарный остаток по графику. Для одиночного обещания
        // совпадает с «next», поэтому показываем отдельно только для рассрочки.
        $whole = null;
        if ($isInstallment && $unmet->count() > 1) {
            $wholeAmount = $debt->plan_remaining !== null
                ? (float) $debt->plan_remaining
                : ($debt->debt_amount !== null ? (float) $debt->debt_amount : null);

            $whole = [
                'amount' => $wholeAmount,
                'url' => route('student.debt.course.pay-all', $debt->course_id),
            ];
        }

        // Перенос даты: доступен на ближайшем непогашенном обещании, если студент
        // ещё не переносил его сам.
        $reschedule = null;
        if ($nextPromise instanceof PaymentPromise && $nextPromise->student_rescheduled_at === null) {
            $reschedule = [
                'promise_id' => $nextPromise->id,
                'url' => route('student.debt.promise.reschedule', $nextPromise),
                'current_date' => $nextPromise->promised_at?->format('Y-m-d'),
            ];
        }

        return [
            'type' => 'arrangement',
            'next' => $next,
            'whole' => $whole,
            'installment' => $isInstallment,
            // Кастомная частичная оплата постит сюда с полем amount (границы —
            // [next_amount, remaining]); прана — опциональным prana_amount.
            'pay_all_url' => route('student.debt.course.pay-all', $debt->course_id),
            'next_amount' => $next['amount'] ?? null,
            'remaining' => $whole['amount'] ?? ($next['amount'] ?? null),
            'reschedule' => $reschedule,
        ];
    }

    /**
     * @return array{type: 'tariff'|'none', full: array|null, blocks: list<array>, unpriced_blocks: list<int>, bundle: array|null}
     */
    private function tariffOptions(object $debt, ?User $user = null): array
    {
        $courseId = (int) $debt->course_id;
        /** @var list<int> $debtBlocks */
        $debtBlocks = array_values(array_map('intval', $debt->debt_block_numbers ?? []));

        if (empty($debtBlocks)) {
            return ['type' => 'none', 'full' => null, 'blocks' => [], 'unpriced_blocks' => [], 'bundle' => null];
        }

        $tariffs = Tariff::query()
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->get();

        // «Полный курс» предлагаем только когда студент должен ВЕСЬ курс (классика
        // «без оплат»): иначе full переплачивает за уже оплаченные блоки. Считаем
        // «весь курс» = число блоков долга совпадает с числом блоков курса.
        // Прямой count вместо relation — не зависим от eager-load / preventLazyLoading.
        $courseBlockCount = \App\Models\CourseBlock::where('course_id', $courseId)->count();
        $full = null;
        if ($courseBlockCount > 0 && count($debtBlocks) >= $courseBlockCount) {
            $fullTariff = $tariffs->first(fn (Tariff $t) => $t->accessKey() === 'full');
            if ($fullTariff instanceof Tariff) {
                $full = [
                    'title' => (string) $fullTariff->title,
                    'url' => route('checkout.show', $fullTariff),
                ];
            }
        }

        // Поблочно: для каждого неоплаченного блока ищем активный тариф целого
        // блока (block_N). Половинные (block_N_hH) в Phase 1 не собираем.
        $blockOptions = [];
        $unpriced = [];
        foreach ($debtBlocks as $n) {
            $tariff = $tariffs->first(fn (Tariff $t) => $t->type === 'block'
                && (int) $t->block_number === $n
                && $t->block_half === null);

            if ($tariff instanceof Tariff) {
                $blockOptions[] = [
                    'number' => $n,
                    'title' => (string) $tariff->title,
                    'url' => route('checkout.show', $tariff),
                ];
            } else {
                $unpriced[] = $n;
            }
        }

        $type = ($full !== null || ! empty($blockOptions)) ? 'tariff' : 'none';

        return [
            'type' => $type,
            'full' => $full,
            'blocks' => $blockOptions,
            'unpriced_blocks' => $unpriced,
        ];
    }
}
