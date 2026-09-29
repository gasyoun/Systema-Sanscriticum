<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H5444: строка расчётного пакета. База положительна и ОБЯЗАНА назвать
 * датированное назначение и версию условия (D17/D15); удержания отрицательны.
 *
 *  - `refund_adjustment` (D11) ссылается на движение возврата и обязательство,
 *    уникальная пара `(refund_movement_id, obligation_id)` не даёт удержать
 *    один возврат дважды; оказанный блок удержать нельзя вовсе;
 *  - `direct_receipt_offset` (D16) гасит обязательство института перед
 *    преподавателем суммой, которую он получил от студента напрямую;
 *    доказательство потребляется один раз (unique).
 */
class TeacherPayoutPackageLine extends Model
{
    public const UPDATED_AT = null;

    public const KIND_BASE = 'base';

    public const KIND_ADVANCE = 'advance';

    public const KIND_OFFSET = 'offset';

    public const KIND_REFUND_ADJUSTMENT = 'refund_adjustment';

    public const KIND_DIRECT_RECEIPT_OFFSET = 'direct_receipt_offset';

    /** Строки, попадающие в соответствующую колонку итога пакета. */
    public const DEDUCTION_COLUMNS = [
        self::KIND_ADVANCE => 'advance_kopecks',
        self::KIND_OFFSET => 'offset_kopecks',
        self::KIND_DIRECT_RECEIPT_OFFSET => 'offset_kopecks',
        self::KIND_REFUND_ADJUSTMENT => 'refund_adjustment_kopecks',
    ];

    protected $fillable = [
        'line_key', 'package_id', 'kind', 'amount_kopecks', 'assignment_id', 'term_id',
        'refund_movement_id', 'obligation_id', 'direct_receipt_movement_id',
        'evidence_key', 'description', 'created_by',
    ];

    protected $casts = [
        'amount_kopecks' => 'integer',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(TeacherPayoutPackage::class, 'package_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TeacherCompensationAssignment::class, 'assignment_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(TeacherCompensationTerm::class, 'term_id');
    }
}
