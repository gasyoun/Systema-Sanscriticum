<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * H2762 — guest-capable storefront experiment log (`storefront_events`).
 *
 * Do not overload GameEvent (drills) or ActivityEvent (auth-only).
 */
class StorefrontAnalyticsEvent extends Model
{
    public const UPDATED_AT = null;

    public const CARD_IMPRESSION = 'card_impression';

    public const NEXT_STEP_CLICK = 'next_step_click';

    public const SAMPLE_PLAY = 'sample_play';

    public const BEGIN_CHECKOUT = 'begin_checkout';

    public const EXPERIMENT_NEXT_STEP = 'next_step';

    public const EXPERIMENT_CTA_AB = 'cta_ab';

    public const EVENTS = [
        self::CARD_IMPRESSION,
        self::NEXT_STEP_CLICK,
        self::SAMPLE_PLAY,
        self::BEGIN_CHECKOUT,
    ];

    protected $table = 'storefront_events';

    protected $fillable = [
        'event_name',
        'experiment',
        'variant',
        'flagship_key',
        'course_id',
        'target',
        'visitor_id',
        'user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
