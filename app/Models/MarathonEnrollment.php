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

    /** Arm A — control, name-masked leaderboard (see PranaLeaderboard). Default for everyone. */
    public const ARM_A = 'a';

    /** Arm B — treatment, un-masked leaderboard. Gated OFF by default, see config/marathon.php. */
    public const ARM_B = 'b';

    /**
     * H3330 — sequential warm-tail waves (MG ruling 22-08-2026,
     * MONETIZATION_PLAN_2026H2 §3). Wave 1 keeps the flagship coupon series;
     * wave 2 receives the membership-offer variant. NOT a random split —
     * assignment is fixed by the enrolment moment against the config cutoff
     * (marathon.warm_tail_wave2_from), so every launch window sees exactly
     * one offer and the mapping is reproducible from data alone.
     */
    public const WAVE_FLAGSHIP = 'flagship';

    public const WAVE_MEMBERSHIP = 'membership';

    protected $fillable = [
        'lead_id',
        'track',
        'cohort',
        'quiz_goal',
        'quiz_level',
        'ab_arm',
        'day2_question',
        'day2_voice_telegram_file_id',
        'day2_voice_disk',
        'day2_voice_path',
        'day2_voice_received_at',
        'day2_voice_reviewed_at',
        'day2_voice_curator_note',
        'day0_started_at',
        'day1_completed_at',
        'day2_completed_at',
        'day1_engaged_at',
        'day2_engaged_at',
        'day1_quiz_seconds',
        'day2_quiz_seconds',
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
        'day2_voice_received_at' => 'datetime',
        'day2_voice_reviewed_at' => 'datetime',
        'day1_quiz_seconds' => 'integer',
        'day2_quiz_seconds' => 'integer',
        'consultation_booked_at' => 'datetime',
        'recording_sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'warm_tail_last_day_sent' => 'integer',
        'quiz_level' => 'integer',
    ];

    /**
     * Human-readable quiz duration for enrollee UI and Filament admin.
     * Null when the day was never finished or duration was not posted.
     */
    public function formatQuizDuration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds < 0) {
            return null;
        }

        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        if ($m === 0) {
            return "{$s} сек";
        }

        return $s === 0 ? "{$m} мин" : "{$m} мин {$s} сек";
    }

    /**
     * Clear tap-quiz completion so the enrollee can re-take Day 1 and/or Day 2
     * (admin-only, Filament «Марафон: опросы»). Does not touch delivery clocks
     * (day{N}_completed_at), paid track, or day2_question.
     *
     * @param  1|2|null  $day  null = both days
     */
    public function resetQuizEngagement(?int $day = null): void
    {
        $updates = [];

        if ($day === null || $day === 1) {
            $updates['day1_engaged_at'] = null;
            $updates['day1_quiz_seconds'] = null;
        }

        if ($day === null || $day === 2) {
            $updates['day2_engaged_at'] = null;
            $updates['day2_quiz_seconds'] = null;
        }

        if ($updates !== []) {
            $this->update($updates);
        }
    }

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

    /** H445 Phase 4 (H546) — has the Day-2 mantra-reading voice note arrived from Telegram? */
    public function hasSubmittedDay2Voice(): bool
    {
        return $this->day2_voice_received_at !== null;
    }

    /**
     * H546 §2 — curator review is a paid-track-only lane; the free track is
     * self-assessed (H445 §1) and never enters the review queue.
     */
    public function needsDay2VoiceReview(): bool
    {
        return $this->isPaidTrack()
            && $this->hasSubmittedDay2Voice()
            && $this->day2_voice_reviewed_at === null;
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

    /**
     * H3330 — which sequential wave this registrant's warm tail belongs to.
     * Fixed by the enrolment moment (day0_started_at) against the config
     * cutoff marathon.warm_tail_wave2_from: starts before the cutoff date
     * (or whenever the cutoff is unset/empty) stay on the flagship-coupon
     * series; starts on/after it get the membership-offer variant. Stable
     * for a given enrolment forever — flipping the cutoff mid-flight never
     * re-brands an already-started tail, it only routes new enrolments.
     */
    public function warmTailWave(): string
    {
        $from = trim((string) config('marathon.warm_tail_wave2_from', ''));

        if ($from === '') {
            return self::WAVE_FLAGSHIP;
        }

        $cutoff = Carbon::parse($from)->startOfDay();

        return $this->day0_started_at->startOfDay()->greaterThanOrEqualTo($cutoff)
            ? self::WAVE_MEMBERSHIP
            : self::WAVE_FLAGSHIP;
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
