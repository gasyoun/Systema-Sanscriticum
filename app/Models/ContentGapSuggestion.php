<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CAI3 content-gap detector output: one row per (category, window) snapshot,
 * ranked by curator-time-saved potential ("deflection_score", same metric as
 * `support:topic-ranking`) and flagged `has_content` when an active support
 * MessageTemplate already exists for the category. See
 * docs/ROADMAP_CONTENT_AI_2026_2027.md CAI3. Written by `content:detect-gaps`.
 */
class ContentGapSuggestion extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'category',
        'window_since',
        'window_until',
        'chat_days',
        'queries',
        'human_replies',
        'ai_sent',
        'unanswered',
        'curator_minutes',
        'auto_rate',
        'deflection_score',
        'has_content',
        'status',
    ];

    protected $casts = [
        // Explicit Y-m-d (not the bare 'date' cast) — updateOrCreate matches
        // on the raw attribute string, and the bare cast round-trips with a
        // time component that breaks the match on re-run.
        'window_since' => 'date:Y-m-d',
        'window_until' => 'date:Y-m-d',
        'auto_rate' => 'decimal:3',
        'has_content' => 'boolean',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /** Ripe for content: no existing template AND deflection above the noise floor. */
    public function scopeGaps(Builder $query): Builder
    {
        return $query->where('has_content', false)->where('deflection_score', '>', 0);
    }
}
