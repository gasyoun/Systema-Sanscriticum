<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\DebtorsReport;
use Illuminate\Support\Collection;

/**
 * Чекаут целого блока, который у студента уже оплачен (кейс студентки 16-11-2026).
 *
 * Студентка открыла сохранённые ссылки прошлого месяца (/checkout/<тариф блока
 * 12> и <блока 3>) и заплатила второй раз за уже оплаченные блоки, хотя хотела
 * 13 и 4. Чекаут этого не проверял. Теперь страница вместо формы говорит
 * «Блок N уже оплачен» и даёт кнопку следующего неоплаченного блока, а
 * createPayment отказывает авторитетно (форма могла быть открыта до оплаты).
 *
 * Только целые блоки (type=block, block_number, без половины), только вошедший
 * студент и не подарок. Платёж-половина блок целиком не покрывает, поэтому в
 * покрытии не участвует: после h1 можно доплатить блок.
 */
class AlreadyPaidBlockGuard
{
    /**
     * @return array{block: int, next: ?array{number: int, title: string, url: string, amount: float}}|null
     */
    public function check(?User $user, Tariff $tariff): ?array
    {
        if (! config('features.checkout_already_paid_block_guard')) {
            return null;
        }

        if ($user === null
            || $tariff->course_id === null
            || $tariff->type !== 'block'
            || $tariff->block_number === null
            || $tariff->block_half !== null
        ) {
            return null;
        }

        $block = (int) $tariff->block_number;
        $payments = $this->wholeBlockPayments($user, (int) $tariff->course_id);

        if (! $this->covers($payments, $block)) {
            return null;
        }

        return [
            'block' => $block,
            'next' => $this->nextUnpaid($user, $tariff, $payments),
        ];
    }

    /**
     * @return Collection<int, Payment>
     */
    private function wholeBlockPayments(User $user, int $courseId): Collection
    {
        $payments = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->whereIn('status', Payment::PAID_STATUSES)
            ->where('is_conditional', false)
            ->get(['tariff', 'start_block', 'end_block']);

        return DebtorsReport::coveringPayments($payments)
            ->reject(fn (Payment $p): bool => (bool) preg_match('/_h\d+$/', (string) $p->tariff))
            ->values();
    }

    /**
     * @param  Collection<int, Payment>  $payments
     */
    private function covers(Collection $payments, int $block): bool
    {
        foreach ($payments as $p) {
            $start = $p->start_block !== null ? (int) $p->start_block : null;
            $end = $p->end_block !== null ? (int) $p->end_block : null;
            if (DebtorsReport::paymentCovers($start, $end, $block)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ближайший после оплаченного блок без оплаты, у которого есть активный
     * тариф целого блока. Нет такого — кнопку не рисуем.
     *
     * @param  Collection<int, Payment>  $payments
     * @return array{number: int, title: string, url: string, amount: float}|null
     */
    private function nextUnpaid(User $user, Tariff $tariff, Collection $payments): ?array
    {
        $next = Tariff::query()
            ->where('course_id', $tariff->course_id)
            ->where('is_active', true)
            ->where('type', 'block')
            ->whereNull('block_half')
            ->where('block_number', '>', (int) $tariff->block_number)
            ->orderBy('block_number')
            ->get()
            ->first(fn (Tariff $t): bool => ! $this->covers($payments, (int) $t->block_number));

        if (! $next instanceof Tariff) {
            return null;
        }

        return [
            'number' => (int) $next->block_number,
            'title' => (string) $next->title,
            'url' => route('checkout.show', $next),
            'amount' => round($next->calculateFinalPriceForUser($user), 2),
        ];
    }
}
