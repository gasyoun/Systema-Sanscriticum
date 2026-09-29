<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TochkaOutgoingTransfer extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = ['provider_transaction_id', 'provider_payment_id', 'statement_id', 'account_tail', 'booked_on', 'amount_kopecks', 'currency', 'document_no', 'recipient_inn_hmac', 'recipient_account_hmac', 'purpose_digest', 'payload_fingerprint', 'imported_at'];

    protected $casts = ['booked_on' => 'date', 'amount_kopecks' => 'integer', 'imported_at' => 'datetime'];
}
