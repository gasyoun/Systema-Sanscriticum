<?php

declare(strict_types=1);

namespace App\Services\Payout;

/**
 * H5444 (D15/D17): ставку не из чего вывести — и выводить НЕЛЬЗЯ.
 *
 * Бросается, когда на дату расчёта нет действующего датированного назначения
 * компенсации. Ни роль, ни доступ к курсу, ни `courses.salary_value`, ни
 * подразумеваемый процент из прошлой выплаты подстановкой не служат: такая
 * строка — «ключи на деньги, которых никто не обещал». Вызывающий обязан
 * оставить случай исключением сверки до рулинга человека (см. H5250).
 */
final class UnresolvedCompensation extends \RuntimeException
{
    public function __construct(
        public readonly int $teacherId,
        public readonly ?int $courseId,
        public readonly string $onDate,
        string $detail = '',
    ) {
        parent::__construct(trim(sprintf(
            'payout: no dated compensation assignment for teacher %d%s on %s — a rate is never inferred from roles, course fields or past payouts. %s',
            $teacherId,
            $courseId !== null ? " / course {$courseId}" : '',
            $onDate,
            $detail,
        )));
    }
}
