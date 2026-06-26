<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReactivationTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Отчёт реактивации «уснувшей» базы. Тонкий слой НАД {@see DebtorsReport}:
 * сегмент «не продлил + без оплат» (block-gap + должники) уже посчитан там
 * правильно — с исключениями (Покинул/Исключён/Льготник/Выпускник), рассрочками,
 * депозитами и скидками. Здесь добавляется только подсказка куратору: какой
 * win/loss-шаблон `/реактивация-*` предложить кандидату.
 *
 * Только чтение: ничего не рассылает. Реальную отправку делает человек по
 * подсказанному шаблону (как и было в ручном процессе владельца).
 */
class ReactivationReport
{
    public function __construct(private DebtorsReport $debtors) {}

    /**
     * Линза по годам (год = год даты блока). Пусто = все годы. Fluent —
     * проксирует в DebtorsReport.
     *
     * @param  array<int|string>  $years
     */
    public function forYears(array $years): self
    {
        $this->debtors->forYears($years);

        return $this;
    }

    /** Базовый отчёт-источник (для дат блоков, сумм долга, рассрочек). */
    public function debtors(): DebtorsReport
    {
        return $this->debtors;
    }

    /**
     * Кандидаты на реактивацию = строки DebtorsReport (тот же сегмент и те же
     * исключения). Каждая строка несёт `debt_type`, `course_id`,
     * `ref_block_number`.
     */
    public function query(): Builder
    {
        return $this->debtors->query();
    }

    /**
     * Подсказанный шаблон для строки-кандидата. Учитывает активную/просроченную
     * рассрочку (тогда повод — цена) и тип «долга» из DebtorsReport.
     *
     * @param  Model  $row  строка из {@see query()} (User + d.* поля)
     */
    public function suggestTemplate(Model $row): ReactivationTemplate
    {
        $debtType = (string) ($row->getAttribute('debt_type') ?? '');
        $hasPromise = $this->debtors->promiseFor(
            (int) $row->getKey(),
            (int) $row->getAttribute('course_id'),
        ) !== null;

        return ReactivationTemplate::suggestFor($debtType, $hasPromise);
    }
}
