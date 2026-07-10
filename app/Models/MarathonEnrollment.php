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
 *
 * H445 Phase 1 — `cohort` distinguishes the August all-zero cohort (`zero`,
 * cyrillic-only, this class's original design) from the January deva-entry
 * follow-on (`deva`) that reuses this exact engine with a different content
 * pack (config('marathon.cohorts.<cohort>.*'), read via content() below).
 * Every enrollment defaults to `zero` today — a January landing/route that
 * actually sets `deva` is its own future phase, see Uprava/handoffs/H445-*.md.
 * Same drip/track/consultation machinery either way.
 */
class MarathonEnrollment extends Model
{
    use HasFactory;

    public const TRACK_FREE = 'free';

    public const TRACK_PAID = 'paid';

    /** August cohort — истинно нулевая, только кириллица (H440 as-built). */
    public const COHORT_ZERO = 'zero';

    /** January cohort — деванагари-входная (H445 §1, engine reuse of H440). */
    public const COHORT_DEVA = 'deva';

    protected $fillable = [
        'lead_id',
        'track',
        'cohort',
        'quiz_goal',
        'quiz_level',
        'day2_question',
        'day0_started_at',
        'day1_completed_at',
        'day2_completed_at',
        'day1_engaged_at',
        'day2_engaged_at',
        'consultation_booked_at',
        'recording_sent_at',
        'paid_at',
        'warm_tail_last_day_sent',
    ];

    protected $casts = [
        'day0_started_at' => 'datetime',
        'day1_completed_at' => 'datetime',
        'day2_completed_at' => 'datetime',
        'day1_engaged_at' => 'datetime',
        'day2_engaged_at' => 'datetime',
        'consultation_booked_at' => 'datetime',
        'recording_sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'warm_tail_last_day_sent' => 'integer',
        'quiz_level' => 'integer',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function isPaidTrack(): bool
    {
        return $this->track === self::TRACK_PAID;
    }

    public function isDevaCohort(): bool
    {
        return $this->cohort === self::COHORT_DEVA;
    }

    /**
     * H445 Phase 2 — the `deva` cohort's level-quiz (layered ON TOP OF the
     * intent-quiz, `quiz_goal` — the August cohort never takes this, there's
     * nothing to grade for an all-zero audience, see H440 §1a).
     */
    public function hasTakenLevelQuiz(): bool
    {
        return $this->quiz_level !== null;
    }

    /**
     * H445 Phase 1 — resolves a content key for this enrollment's cohort,
     * falling back to the shared `marathon.<key>` default when the cohort
     * carries no override. Lets `config('marathon.cohorts.deva.*')` stay a
     * sparse overlay (only day1/day2 content actually differs by cohort —
     * price/host/schedule/day3 stay shared) instead of a full content fork.
     */
    public function content(string $key): mixed
    {
        return data_get(config('marathon.cohorts'), "{$this->cohort}.{$key}")
            ?? config("marathon.{$key}");
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

    /**
     * H440 Phase 6 — which warm-tail day (1..warm_tail_days) this unpaid
     * registrant is on, or null if not yet in the window (still inside the
     * 3-day marathon) or past it (window elapsed, stop sending). Counted
     * from the same personal day0_started_at clock as currentDay() — Day 4
     * overall = warm-tail day 1.
     */
    public function warmTailDay(?Carbon $now = null): ?int
    {
        $now ??= now();
        $elapsedDays = (int) $this->day0_started_at->startOfDay()->diffInDays($now->copy()->startOfDay());
        $warmTailDay = $elapsedDays - 3;

        $windowDays = (int) config('marathon.warm_tail_days', 13);

        if ($warmTailDay < 1 || $warmTailDay > $windowDays) {
            return null;
        }

        return $warmTailDay;
    }
}
