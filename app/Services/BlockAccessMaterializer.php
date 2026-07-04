<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Мульти-блочный доступ через нулевые access-only sibling-строки.
 *
 * Проблема: доступ к уроку гейтится по КЛЮЧУ `payments.tariff` (`block_N`/`full`,
 * см. Lesson::isUnlockedBy), а один Payment несёт ровно один ключ. Платёж-
 * договорённость с диапазоном блоков N..M пишется ключом `block_N` → уроки
 * блоков N+1..M остаются закрыты, хотя оплачены. Тем же болеет менеджерский
 * PromiseFulfillment (один ключ на диапазон).
 *
 * Решение (модель MG): основной платёж хранит полную сумму и свой ключ; на
 * каждый недостающий блок диапазона создаётся НУЛЕВАЯ access-only sibling-строка
 * с ключом `block_X`. Она:
 *   - открывает уроки блока X (ключ попадает в getUserUnlockedTariffs);
 *   - вне финансов: PaymentObserver::isSyncable исключает transaction_id
 *     'access_grant_#...' (нулевые ЛЕГИТИМНЫЕ оплаты по-прежнему синкаются);
 *   - не искажает долг: диапазон уже покрыт основным платежом (StudentDebtsService
 *     считает по start_block..end_block, не по ключу) — sibling нужен лишь для ключа;
 *   - идемпотентна: повтор вебхука не плодит дубли.
 *
 * Создаётся через withoutEvents — без рекурсии в processSuccessfulPayment, без
 * писем/праны/реферала/синка.
 */
class BlockAccessMaterializer
{
    public const GRANT_PREFIX = 'access_grant_#';

    /**
     * @return int сколько sibling-строк создано
     */
    public function materialize(Payment $payment): int
    {
        // Не трогаем: conditional (доступ под обещание — свои per-block платежи
        // уже создаёт ConditionalAccessGranter), сами sibling-строки, платежи без
        // курса/юзера, и платежи с ключом 'full' (он открывает все блоки сам).
        if ($payment->is_conditional
            || ! $payment->user_id
            || ! $payment->course_id
            || $payment->tariff === 'full'
            || $this->isAccessGrant($payment)) {
            return 0;
        }

        $start = $payment->start_block;
        $end = $payment->end_block;
        if ($start === null || $end === null || $end <= $start) {
            return 0; // одиночный блок или нет диапазона — ключа основного платежа хватает
        }

        // Уже оплаченные (любым платежом) ключи блоков этого курса — не дублируем.
        $ownedKeys = Payment::query()
            ->where('user_id', $payment->user_id)
            ->where('course_id', $payment->course_id)
            ->paid()
            ->pluck('tariff')
            ->all();
        $ownedKeys = array_flip($ownedKeys);

        $created = 0;
        DB::transaction(function () use ($payment, $start, $end, $ownedKeys, &$created): void {
            for ($n = (int) $start; $n <= (int) $end; $n++) {
                $key = 'block_'.$n;
                if (isset($ownedKeys[$key])) {
                    continue; // уже открыт (в т.ч. ключом самого основного платежа)
                }

                Payment::withoutEvents(fn () => Payment::create([
                    'user_id' => $payment->user_id,
                    'course_id' => $payment->course_id,
                    'amount' => 0,
                    'tariff' => $key,
                    'status' => 'paid',
                    'start_block' => $n,
                    'end_block' => $n,
                    'is_conditional' => false,
                    'transaction_id' => self::GRANT_PREFIX.$payment->id,
                ]));

                $ownedKeys[$key] = true; // защита от повтора внутри одного прогона
                $created++;
            }
        });

        return $created;
    }

    /** Снять access-only siblings основного платежа (при откате/возврате основного). */
    public function removeSiblingsOf(Payment $primary): int
    {
        return Payment::withoutEvents(fn () => Payment::query()
            ->where('transaction_id', self::GRANT_PREFIX.$primary->id)
            ->delete());
    }

    private function isAccessGrant(Payment $payment): bool
    {
        return is_string($payment->transaction_id)
            && str_starts_with($payment->transaction_id, self::GRANT_PREFIX);
    }
}
