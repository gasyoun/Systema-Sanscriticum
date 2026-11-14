<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\BlockMonthRecognition;

/**
 * H8245 — B2B-оплата «за группу целиком»: юрлицо/студия платит за всех учеников
 * группы одним платежом (Адара → гр. «Грамматика по Кочергиной», курс 463).
 *
 * Ученики группы с оплаченным b2b_group-платежом получают ВИРТУАЛЬНЫЕ ключи
 * доступа на запросе — то же устройство, что у клубного членства
 * (ClubEntitlement::extraTariffKeys, H2646): ключ нигде не хранится, оплата
 * b2b-платежа открывает уроки немедленно, откат/непоступление следующего
 * платежа закрывает так же немедленно — без миграций и без «забытых» строк
 * в payments. УЧЕНИКИ группы при этом остаются в group_user: откат b2b-платежа
 * не детачит их (Payment::grantsCourseAccess отсекает b2b_group).
 *
 * Флаг features.b2b_group_payment (дефолт OFF, env B2B_GROUP_PAYMENT): выключено —
 * все методы no-op, поведение живого контура не меняется ни в одной точке.
 * Сам b2b_group-платёж — чисто денежная строка (Payment::processB2bGroupPaid):
 * он не выдаёт доступ и плательщику. Приём денег — существующие пути:
 * нал (payment_method='cash', received_account=school), счёт юрлицу
 * (provider=invoice, H2017, pending до сверки), Точка.
 */
final class B2bGroupEntitlement
{
    public static function enabled(): bool
    {
        return (bool) config('features.b2b_group_payment');
    }

    /**
     * Виртуальные ключи ученика по его группам курса. Покрытие группы =
     * оплаченный (paid) не-conditional b2b_group-платёж курса с group_id одной
     * из групп ученика. (null, null) → 'full' (весь курс); диапазон
     * start_block..end_block → 'block_N' на каждый покрытый блок
     * (BlockMonthRecognition::coveredBlockNumbers — тот же предикат, что у
     * зарплатной раскладки, поэтому ключи всегда совпадают с признанной выручкой).
     *
     * @return list<string>
     */
    public function extraTariffKeysFor(?User $user, ?Course $course): array
    {
        if (! self::enabled() || $user === null || $course === null) {
            return [];
        }

        $courseGroupIds = $course->groups()->pluck('groups.id')->map(fn ($id) => (int) $id)->all();
        if ($courseGroupIds === []) {
            return [];
        }

        $userGroupIds = $user->groups->map(fn ($group) => (int) $group->id)->all();
        $coveredGroupIds = array_values(array_intersect($courseGroupIds, $userGroupIds));
        if ($coveredGroupIds === []) {
            return [];
        }

        $payments = Payment::query()
            ->where('course_id', $course->id)
            ->whereIn('group_id', $coveredGroupIds)
            ->where('tariff', Payment::TARIFF_B2B_GROUP)
            ->paid()
            ->real()
            ->get();

        if ($payments->isEmpty()) {
            return [];
        }

        $blockNumbers = $course->lessons()
            ->whereNotNull('block_number')
            ->distinct()
            ->orderBy('block_number')
            ->pluck('block_number')
            ->map(fn ($n) => (int) $n)
            ->all();

        $keys = [];
        foreach ($payments as $payment) {
            if ($payment->start_block === null && $payment->end_block === null) {
                $keys[] = 'full';

                continue;
            }

            foreach (BlockMonthRecognition::coveredBlockNumbers($payment->start_block, $payment->end_block, $blockNumbers) as $blockNumber) {
                $keys[] = 'block_'.$blockNumber;
            }
        }

        return array_values(array_unique($keys));
    }
}
