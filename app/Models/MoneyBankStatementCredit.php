<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * H5480 (P3b): строка ЗАЧИСЛЕНИЯ банковской выписки. Append-only (триггеры в
 * миграции), идемпотентна по row_hash.
 *
 * Привязать строку к ученику структурно нельзя (H4645: в выписке счёта Точки
 * нет идентификатора ученика), поэтому сверка идёт дневными агрегатами, а
 * KIND_* — то немногое, что банк о строке всё-таки говорит.
 */
class MoneyBankStatementCredit extends Model
{
    /** Расчёт по QR (СБП): банк переводит выручку пооперационно. */
    public const KIND_QR = 'qr_settlement';

    /** Эквайринг карт: ОДНА строка за день на много оплат, уже за вычетом комиссии. */
    public const KIND_CARD_AGGREGATE = 'card_acquiring_aggregate';

    /** Прямой перевод на счёт (в т.ч. от юрлица/ученика). */
    public const KIND_TRANSFER = 'transfer';

    /** Не распознано — повод для unknown_purpose, а не для догадки. */
    public const KIND_OTHER = 'other';

    /** Машинные строки банка: назначение можно хранить, ПД в нём нет. */
    public const MACHINE_KINDS = [self::KIND_QR, self::KIND_CARD_AGGREGATE];

    public const UPDATED_AT = null;

    protected $table = 'money_bank_statement_credits';

    protected $fillable = [
        'statement_id', 'row_hash', 'value_date', 'amount_kopecks', 'currency',
        'kind', 'doc_no', 'order_ref', 'qr_id', 'purpose', 'purpose_digest',
    ];

    protected $casts = [
        'statement_id' => 'integer',
        'value_date' => 'date',
        'amount_kopecks' => 'integer',
        'order_ref' => 'integer',
    ];
}
