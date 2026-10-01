<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Чек Digital Kassa по платежу Точки. receipt_id для DK = «ss-{payment_id}-{attempt}»:
 * DK идемпотентен по receipt_id (сетевой ретрай — тот же id), а после 400 требует
 * новый id — тогда attempt++ (вручную, fiscal:retry-digitalkassa --new-id).
 */
class FiscalReceipt extends Model
{
    public const PROVIDER_DIGITALKASSA = 'digitalkassa';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** Тег 1214 признак способа расчёта. */
    public const METHOD_FULL_PREPAYMENT = 1;

    public const METHOD_FULL_PAYMENT = 4;

    protected $fillable = [
        'payment_id',
        'provider',
        'attempt',
        'status',
        'item_name',
        'payment_method',
        'request_json',
        'fiscal_num',
        'fiscal_sign',
        'receipt_url',
        'registered_at',
        'last_error',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'payment_method' => 'integer',
        'request_json' => 'array',
        'fiscal_num' => 'integer',
        'fiscal_sign' => 'integer',
        'registered_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function receiptId(): string
    {
        return 'ss-'.$this->payment_id.'-'.$this->attempt;
    }

    /** Маппинг enum Точки (paymentMethod) → тег 1214, паритет 1:1 со старыми чеками. */
    public static function methodFromTochka(string $tochkaMethod): int
    {
        return $tochkaMethod === 'full_prepayment'
            ? self::METHOD_FULL_PREPAYMENT
            : self::METHOD_FULL_PAYMENT;
    }
}
