<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\DebtorsReport;
use App\Services\ReactivationReport;
use App\Services\StuckStudentsReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * GC-A1 segment engine (H1637): a saved, named, data-driven filter over
 * students — group membership, behavior (last activity / completed lessons /
 * tariff ownership), attendance, lead status, debtor, UTM. Rank 6 of the
 * parity ladder (docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §2.2):
 * evaluating a segment may only SELECT against ranks 1-5 tables (payments,
 * access, lead, tariff) — it must NEVER write to them. This model has no
 * relationship/method that persists to those tables; `matchingStudents()`
 * and its helpers are pure read queries.
 *
 * Three built-in segments (`is_builtin=true`, seeded by SegmentSeeder) wrap
 * the existing report services 1:1 — same query, same rows, nothing
 * reinterpreted:
 *   - BUILTIN_REACTIVATION  → ReactivationReport::query()
 *   - BUILTIN_DEBTORS       → DebtorsReport::query()
 *   - BUILTIN_STUCK_STUDENTS→ StuckStudentsReport::query()
 *
 * Custom segments store a `criteria` array of typed conditions, ALL
 * AND-combined (no OR/nesting in v1 — a saved filter, not a general query
 * builder). Supported criterion shapes:
 *   {"type":"group","group_id":N}
 *   {"type":"last_activity_days","min_days":N}           -- inactive >= N days
 *   {"type":"completed_lesson","lesson_id":N}
 *   {"type":"tariff_owned","course_id":N,"tariff":"full"} -- has a PAID, non-conditional payment
 *   {"type":"attendance_below","course_id":N,"rate":0.5}  -- present/expected < rate
 *   {"type":"lead_status","status":"new"}                 -- joined by email to leads.status
 *   {"type":"debtor"}                                      -- appears in DebtorsReport::query()
 *   {"type":"utm_source","value":"vk"}
 *
 * Evaluation is gated behind the `marketing_segments` feature flag (deploy
 * switch, same early-return pattern as CampaignSender::send()) — with the
 * flag OFF, `matchingStudentsQuery()` returns an unconditionally-empty query.
 */
class Segment extends Model
{
    use HasFactory;

    public const BUILTIN_REACTIVATION = 'reactivation';

    public const BUILTIN_DEBTORS = 'debtors';

    public const BUILTIN_STUCK_STUDENTS = 'stuck_students';

    /** @var list<string> */
    public const BUILTIN_KEYS = [
        self::BUILTIN_REACTIVATION,
        self::BUILTIN_DEBTORS,
        self::BUILTIN_STUCK_STUDENTS,
    ];

    protected $fillable = [
        'name',
        'description',
        'criteria',
        'is_builtin',
        'builtin_key',
        'created_by',
    ];

    protected $casts = [
        'criteria' => 'array',
        'is_builtin' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Evaluates this segment's stored definition into a `User` query.
     * Read-only: never call ->update()/->delete() on the result — this is
     * the ONE entry point every consumer (Filament, future campaigns) must
     * go through, and it is the entry point the `marketing_segments` flag
     * gates.
     *
     * @return Builder<User>
     */
    public function matchingStudentsQuery(): Builder
    {
        if (! (bool) config('features.marketing_segments')) {
            return User::query()->whereRaw('1 = 0');
        }

        if ($this->is_builtin && $this->builtin_key) {
            return $this->builtinQuery();
        }

        return $this->customCriteriaQuery();
    }

    /** @return Collection<int, User> */
    public function matchingStudents(): Collection
    {
        return $this->matchingStudentsQuery()->get();
    }

    /** @return Builder<User> */
    private function builtinQuery(): Builder
    {
        return match ($this->builtin_key) {
            self::BUILTIN_REACTIVATION => app(ReactivationReport::class)->query(),
            self::BUILTIN_DEBTORS => app(DebtorsReport::class)->query(),
            self::BUILTIN_STUCK_STUDENTS => StuckStudentsReport::query(),
            default => User::query()->whereRaw('1 = 0'),
        };
    }

    /** @return Builder<User> */
    private function customCriteriaQuery(): Builder
    {
        $query = User::query();

        foreach ((array) $this->criteria as $criterion) {
            if (! is_array($criterion) || ! isset($criterion['type'])) {
                continue;
            }

            $query = $this->applyCriterion($query, $criterion);
        }

        return $query;
    }

    /**
     * @param  Builder<User>  $query
     * @param  array<string, mixed>  $criterion
     * @return Builder<User>
     */
    private function applyCriterion(Builder $query, array $criterion): Builder
    {
        return match ($criterion['type']) {
            'group' => $query->whereHas(
                'groups',
                fn (Builder $q) => $q->where('groups.id', (int) ($criterion['group_id'] ?? 0)),
            ),
            'last_activity_days' => $this->applyLastActivityDays($query, (int) ($criterion['min_days'] ?? 0)),
            'completed_lesson' => $query->whereHas(
                'completedLessons',
                fn (Builder $q) => $q->where('lessons.id', (int) ($criterion['lesson_id'] ?? 0)),
            ),
            'tariff_owned' => $query->whereHas('payments', function (Builder $q) use ($criterion): void {
                $q->paid()
                    ->where('is_conditional', false)
                    ->where('course_id', (int) ($criterion['course_id'] ?? 0));

                if (! empty($criterion['tariff'])) {
                    $q->where('tariff', (string) $criterion['tariff']);
                }
            }),
            'attendance_below' => $this->applyAttendanceBelow(
                $query,
                (int) ($criterion['course_id'] ?? 0),
                (float) ($criterion['rate'] ?? 1.0),
            ),
            'lead_status' => $this->applyLeadStatus($query, (string) ($criterion['status'] ?? '')),
            'debtor' => $query->whereIn('users.id', $this->debtorUserIds()),
            'utm_source' => $query->where('utm_source', (string) ($criterion['value'] ?? '')),
            default => $query,
        };
    }

    /** @param  Builder<User>  $query
     * @return Builder<User> */
    private function applyLastActivityDays(Builder $query, int $minDays): Builder
    {
        $cutoff = now()->subDays(max($minDays, 0));

        return $query->where(function (Builder $q) use ($cutoff): void {
            $q->whereNull('last_activity_at')
                ->orWhere('last_activity_at', '<', $cutoff);
        });
    }

    /**
     * `present/expected < rate` for the given course, computed via two
     * correlated read-only subqueries (no N+1, no division-by-zero: courses
     * with zero expected classes never match). Kept as a comparison of
     * products rather than a division so it needs no cross-driver CAST.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function applyAttendanceBelow(Builder $query, int $courseId, float $rate): Builder
    {
        $expectedSql = '(SELECT COUNT(*) FROM schedules s '
            .'INNER JOIN group_user gu ON gu.group_id = s.group_id '
            .'WHERE gu.user_id = users.id AND s.course_id = ? '
            .'AND s.start IS NOT NULL AND s.start <= ?)';

        $presentSql = '(SELECT COUNT(*) FROM webinar_attendances wa '
            .'INNER JOIN schedules s2 ON s2.id = wa.schedule_id '
            .'WHERE wa.user_id = users.id AND s2.course_id = ?)';

        return $query->whereRaw(
            "{$expectedSql} > 0 AND {$presentSql} < ? * {$expectedSql}",
            [$courseId, now(), $courseId, $rate, $courseId, now()],
        );
    }

    /**
     * Students matched by email to a Lead in the given stage. `leads` has no
     * FK to `users` — email is the same match key AttributionService uses to
     * link a Lead to its cabinet account, so joining on it here is read-only
     * reuse of an existing convention, not a new coupling.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function applyLeadStatus(Builder $query, string $status): Builder
    {
        if ($status === '') {
            return $query->whereRaw('1 = 0');
        }

        $emails = Lead::query()
            ->where('status', $status)
            ->whereNotNull('email')
            ->pluck('email');

        return $query->whereIn('email', $emails);
    }

    /** @return list<int> */
    private function debtorUserIds(): array
    {
        return app(DebtorsReport::class)->query()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
