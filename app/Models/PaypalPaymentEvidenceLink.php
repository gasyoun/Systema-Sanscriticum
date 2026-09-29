<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PaypalPaymentEvidenceLink extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = ['receipt_id', 'payment_id', 'confirmed_by', 'created_at'];
}
