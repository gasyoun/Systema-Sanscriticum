<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MembershipFunnelEvent extends Model
{
    public const UPDATED_AT = null;

    public const OFFER_VIEW = 'offer_view';

    public const CHECKOUT = 'checkout';

    public const PAYMENT = 'payment';

    public const RENEWAL = 'renewal';

    public const LAPSE = 'lapse';

    public const RESTORATION = 'restoration';

    public const FEATURE_USE = 'feature_use';

    protected $fillable = [
        'event_name', 'user_id', 'course_id', 'tariff_id', 'payment_id',
        'visitor_hash', 'tier', 'term_months', 'source_site', 'archive_key',
        'feature', 'metadata', 'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'term_months' => 'integer',
        'created_at' => 'datetime',
    ];
}
