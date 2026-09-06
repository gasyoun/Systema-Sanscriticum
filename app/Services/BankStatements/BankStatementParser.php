<?php

declare(strict_types=1);

namespace App\Services\BankStatements;

/**
 * Парсер выписки банка → расходные строки контура «Расходы ИП» (H4200).
 *
 * Каждый формат (Точка/Сбер/PayPal) — свой парсер; контракт один: на выходе
 * ТОЛЬКО расходные (дебетовые, деньги наружу) строки, суммы — Decimal-exact
 * строки «1234.56», дата «Y-m-d», валюта — 3 буквы. Строки зачислений,
 * внутренние переводы между своими счетами и служебные «Итого» не проходят —
 * они либо skip (stats), либо ошибка.
 *
 * Непонятный вход — UnparseableStatementException (REFUSE), никогда не
 * молчаливый пропуск: деньги не терпят молчаливых догадок.
 */
interface BankStatementParser
{
    /**
     * @return array{
     *     rows: list<array{date: string, amount: string, currency: string, payee: string, description: ?string}>,
     *     stats: array<string, int>
     * }
     */
    public function parse(string $contents): array;
}
