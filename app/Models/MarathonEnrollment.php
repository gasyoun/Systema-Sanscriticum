<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * H440 — 3-day diagnostic marathon registrant. day0_started_at is a
 * PERSONAL clock (registration moment), never a shared calendar date — the
 * anti-urgency design in Uprava/custdev/MARATHON_DIAGNOSTIC_2026.md §3
 * explicitly rejects a common start date. currentDay() is what the Phase 2
 * drip engine keys off of to decide which day's content to send.
 */
class MarathonEnrollment extends Model
{
    use HasFactory;

    public const TRACK_FREE = 'free';

    public const TRACK_PAID = 'paid';

    protected $fillable = [
        'lead_id',
        'track',
        'quiz_goal',
        'day2_question',
        'day0_started_at',
        'day1_completed_at',
        'day2_completed_at',
        'day1_engaged_at',
        'day2_engaged_at',
        'consultation_booked_at',
        'paid_at',
    ];

    protected $casts = [
        'day0_started_at' => 'datetime',
        'day1_completed_at' => 'datetime',
        'day2_completed_at' => 'datetime',
        'day1_engaged_at' => 'datetime',
        'day2_engaged_at' => 'datetime',
        'consultation_booked_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function isPaidTrack(): bool
    {
        return $this->track === self::TRACK_PAID;
    }

    /** H471 — has the ₽500 "с проверкой" track actually been paid (not just selected at registration)? */
    public function isPaidConfirmed(): bool
    {
        return $this->paid_at !== null;
    }

    /**
     * Which personal marathon day this registrant is on right now, 0-indexed
     * from day0_started_at. Clamped at 3 (Day 3 = live consultation + warm
     * tail; the drip engine doesn't need to distinguish "day 3" from "day 47").
     */
    public function currentDay(?Carbon $now = null): int
    {
        $now ??= now();
        $elapsedDays = (int) $this->day0_started_at->startOfDay()->diffInDays($now->copy()->startOfDay());

        return min($elapsedDays, 3);
    }
}
