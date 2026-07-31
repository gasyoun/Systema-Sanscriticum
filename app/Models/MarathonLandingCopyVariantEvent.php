<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * H2010 — one row per newly-assigned marathon landing visitor
 * (event_type='impression') and one per resulting Lead
 * (event_type='lead'), keyed by copy variant (a|b). Feeds
 * MarathonLandingCopyReport's conversion-rate table.
 */
class MarathonLandingCopyVariantEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'variant',
        'event_type',
    ];
}
