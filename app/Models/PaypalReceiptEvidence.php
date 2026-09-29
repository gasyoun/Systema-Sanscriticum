<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PaypalReceiptEvidence extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $table = 'paypal_receipt_evidence';

    protected $fillable = ['transaction_id', 'completed_on', 'currency', 'gross_minor', 'fee_minor', 'net_minor', 'payer_digest', 'item_digest', 'source_file_sha256', 'payload_fingerprint', 'imported_at'];

    protected $casts = ['completed_on' => 'date', 'gross_minor' => 'integer', 'fee_minor' => 'integer', 'net_minor' => 'integer', 'imported_at' => 'datetime'];
}
