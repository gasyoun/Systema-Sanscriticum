<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * H987 -- RQ4 study participant (SanskritGrammar docs/RQ4_EVALUATION_PROTOCOL_2026.md).
 * Arm: A = on-ramp-first, B = talmud-first.
 */
class Rq4Participant extends Model
{
    public const ARM_ON_RAMP = 'A';

    public const ARM_TALMUD = 'B';

    public const EXPOSURE_LEVELS = ['none', 'kochergina', 'beyond'];

    /** Fixed by MG's ruling (protocol Sec6.2) -- not a config knob. */
    public const RETENTION_WINDOW_DAYS = 28;

    protected $fillable = [
        'user_id',
        'prior_exposure',
        'arm',
        'consented_at',
        'pre_test_completed_at',
        'arm_content_started_at',
        'post_test_completed_at',
        'retention_due_at',
        'retention_test_completed_at',
        'retention_reminder_sent_at',
    ];

    protected $casts = [
        'consented_at' => 'datetime',
        'pre_test_completed_at' => 'datetime',
        'arm_content_started_at' => 'datetime',
        'post_test_completed_at' => 'datetime',
        'retention_due_at' => 'datetime',
        'retention_test_completed_at' => 'datetime',
        'retention_reminder_sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Rq4Response::class, 'participant_id');
    }

    /**
     * Stratified 1:1 arm assignment ("minimisation"): within the given
     * exposure stratum, assign whichever arm currently has fewer enrolled
     * participants (tie broken by a deterministic hash of user_id, so ties
     * don't all resolve the same direction). More precisely balanced than a
     * flat coin flip at small N, which is the realistic regime for a first
     * pilot (protocol Section 5/7).
     */
    public static function assignArm(string $priorExposure, int $userId): string
    {
        $counts = self::query()
            ->where('prior_exposure', $priorExposure)
            ->selectRaw('arm, count(*) as c')
            ->groupBy('arm')
            ->pluck('c', 'arm');

        $onRampCount = (int) ($counts[self::ARM_ON_RAMP] ?? 0);
        $talmudCount = (int) ($counts[self::ARM_TALMUD] ?? 0);

        if ($onRampCount === $talmudCount) {
            return crc32('h987-rq4-arm-tiebreak:'.$userId) % 2 === 0
                ? self::ARM_ON_RAMP
                : self::ARM_TALMUD;
        }

        return $onRampCount < $talmudCount ? self::ARM_ON_RAMP : self::ARM_TALMUD;
    }

    /** Retention test due (post_test done, 4 weeks fixed) and not yet completed or reminded. */
    public function scopeRetentionReminderDue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('retention_due_at')
            ->where('retention_due_at', '<=', now())
            ->whereNull('retention_test_completed_at')
            ->whereNull('retention_reminder_sent_at');
    }
}
