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

    /** Arm A — control, name-masked leaderboard (see PranaLeaderboard). Default for everyone. */
    public const ARM_A = 'a';

    /** Arm B — treatment, un-masked leaderboard. Gated OFF by default, see config/marathon.php. */
    public const ARM_B = 'b';

    protected $fillable = [
        'lead_id',
        'track',
        'quiz_goal',
        'ab_arm',
        'day2_question',
        'day0_started_at',
        'day1_completed_at',
        'day2_completed_at',
        'day1_engaged_at',
        'day2_engaged_at',
        'consultation_booked_at',
        'recording_sent_at',
        'paid_at',
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

    /**
     * H447 §5 leaderboard A/B. Deterministic 50/50 split, stable for a given
     * id forever (same input → same arm, across sessions/requests/deploys).
     *
     * NOTE ON DEVIATION FROM THE HANDOFF WORDING: the design doc specifies
     * "hash of user_id" — but marathon_enrollments is keyed by lead_id, and a
     * registrant has no User account yet at enrolment time (accounts are
     * created lazily at first payment, see MarathonController::pay()). This
     * hashes `lead_id` instead, which is the only stable identity that
     * exists at the point the arm must be fixed. Once a Lead converts to a
     * User (User::lead_id), PranaLeaderboard::armFor() looks the arm back up
     * via that link, so a student's arm is still consistent everywhere they
     * appear as a User. Flagged for a human to confirm this substitution is
     * acceptable, or to add a user_id column instead once accounts exist
     * up-front for this cohort.
     */
    public static function computeArm(int $id): string
    {
        return crc32('h447-marathon-ab:'.$id) % 2 === 0 ? self::ARM_A : self::ARM_B;
    }

    /** Assigns and persists the arm if not already set — idempotent, never flips an existing arm. */
    public function ensureArm(): string
    {
        if ($this->ab_arm === null) {
            $this->ab_arm = self::computeArm($this->lead_id);
            $this->save();
        }

        return $this->ab_arm;
    }
}
