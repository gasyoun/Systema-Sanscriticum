<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * H5480: одна строка зачисления из банковской выписки. Идентификатора
 * студента в ней НЕТ (H4645) — сверка только на уровне дневного агрегата.
 * Назначение платежа не хранится: только sha256 и технические токены.
 *
 * @property int $id
 * @property string $row_hash
 * @property Carbon $booked_on
 * @property int $amount_kopecks
 * @property string $kind
 */
class BankStatementCredit extends Model
{
    public const UPDATED_AT = null;

    /** Пер-QR расчёт СБП: одна строка = один QR. */
    public const KIND_QR = 'qr_settlement';

    /** Эквайринг карт: ДНЕВНОЙ агрегат за вычетом комиссии, много оплат в одной строке. */
    public const KIND_ACQUIRING = 'card_acquiring_aggregate';

    /** Перевод с «Заказ №N» в назначении. */
    public const KIND_TRANSFER = 'transfer';

    /** Всё прочее — не классифицировано, повод для unknown_purpose. */
    public const KIND_OTHER = 'other';

    protected $table = 'bank_statement_credits';

    protected $fillable = [
        'row_hash', 'import_id', 'booked_on', 'amount_kopecks', 'currency',
        'kind', 'doc_no', 'qr_id', 'order_ref', 'purpose_digest',
    ];

    protected $casts = [
        'booked_on' => 'date',
        'amount_kopecks' => 'integer',
        'order_ref' => 'integer',
    ];

    /** @return BelongsTo<BankStatementImport, BankStatementCredit> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'import_id');
    }
}
