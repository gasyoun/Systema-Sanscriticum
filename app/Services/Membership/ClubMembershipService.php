<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Жизненный цикл клубного членства (H2644): выдать период по оплате, снять
 * право по истечении, отключить/вернуть продление.
 *
 * Контур денег. Три инварианта, каждый закрыт тестом:
 *
 *  1. НИКОГДА не раньше срока — снимаем только по `grace_until < now()`
 *     (строго), где grace_until = конец оплаченного периода + грейс.
 *  2. НИКОГДА дважды — снятие идемпотентно (`revoked_at` — единственный
 *     переключатель), а группу снимаем только если у человека не осталось
 *     ни одного действующего периода.
 *  3. НИКОГДА не трогаем группы курсов — отцепляем только те группы, что
 *     привязаны к клубному курсу И БОЛЬШЕ НИ К КАКОМУ ДРУГОМУ. Группа,
 *     разделяемая с обычным курсом, не отцепляется вообще: это отказ
 *     (REFUSED) с логом, а не «отцепим, наверное, можно». Клуб ортогонален
 *     записи на курс (Mode D: «не открывать оплаченные блоки сами»), и job,
 *     дотянувшийся до группы курса, — это инцидент целостности данных.
 */
final class ClubMembershipService
{
    public function __construct(
        private readonly ClubEntitlement $entitlement,
        private readonly MembershipFunnelAnalytics $funnel,
    ) {}

    /** Курс-членство. NULL, пока человек не завёл его в Filament. */
    public function clubCourse(): ?Course
    {
        return $this->entitlement->clubCourse();
    }

    /** Платёж лежит на клубном курсе и реально оплачен. */
    public function isClubPayment(Payment $payment): bool
    {
        $course = $this->clubCourse();

        if (! $course instanceof Course || (int) $payment->course_id !== (int) $course->id) {
            return false;
        }

        return in_array($payment->status, Payment::PAID_STATUSES, true);
    }

    /**
     * Срок платежа в месяцах. Источник — ключ доступа `club_{N}m`, который
     * Tariff::accessKey() выдаёт для тарифа с заполненным membership_months.
     * Платёж на клубном курсе с любым другим ключом (ручной ввод в админке,
     * импорт) получает срок по умолчанию — продать МЕНЬШЕ месяца нельзя,
     * а угадывать больше месяца по сумме мы не будем.
     */
    public function termMonthsFor(Payment $payment): int
    {
        $default = max(1, (int) config('membership.club.default_term_months', 1));
        $max = max($default, (int) config('membership.club.max_term_months', 12));

        if (is_string($payment->tariff) && preg_match('/^(?:club|membership_(?:free|basic|club|top|standard|professional))_(\d+)m$/', $payment->tariff, $m) === 1) {
            return max(1, min($max, (int) $m[1]));
        }

        return $default;
    }

    /** Tier is encoded in the immutable payment key; amounts are never classified. */
    public function tierFor(Payment $payment): ?MembershipTier
    {
        if (! is_string($payment->tariff)) {
            return null;
        }

        if (preg_match('/^membership_(free|basic|club|top|standard|professional)_\d+m$/', $payment->tariff, $m) === 1) {
            return MembershipTier::tryFrom($m[1]);
        }

        // H2644's key already states "club" explicitly. This is not amount guessing.
        if (preg_match('/^club_\d+m$/', $payment->tariff) === 1) {
            return MembershipTier::Club;
        }

        return null;
    }

    /**
     * Создать период по оплате. Идемпотентно по payment_id (unique в схеме).
     *
     * Продления СКЛАДЫВАЮТСЯ ВСТЫК: новый период стартует не «сейчас», а с
     * конца текущего. Иначе продлившийся 25-го числа дарил школе пять дней —
     * ровно то поведение, из-за которого ручное продление выглядит обманом.
     */
    public function syncFromPayment(Payment $payment): ?ClubMembership
    {
        if (! $this->isClubPayment($payment) || ! $payment->user_id) {
            return null;
        }

        $tier = $this->tierFor($payment);
        if ((bool) config('features.membership_tiered', false) && ! $tier instanceof MembershipTier) {
            Log::critical('membership: payment has no explicit tier; period refused', [
                'payment_id' => $payment->id,
                'tariff_key' => $payment->tariff,
            ]);

            return null;
        }
        $tier ??= MembershipTier::Club;

        $existing = ClubMembership::query()->where('payment_id', $payment->id)->first();
        if ($existing instanceof ClubMembership) {
            return $existing;
        }

        $months = $this->termMonthsFor($payment);
        $graceDays = max(0, (int) config('membership.club.grace_days', 3));

        // Встык к самому позднему НЕ снятому периоду, даже если его грейс уже
        // вышел, но демон ещё не отработал: иначе поздний прогон демона
        // укоротил бы только что купленный период.
        $activeTier = $this->activeTierFor($payment->user);
        $isUpgrade = $activeTier instanceof MembershipTier && $tier->rank() > $activeTier->rank();
        $tailEndsAt = ClubMembership::query()
            ->where('user_id', $payment->user_id)
            ->whereNull('revoked_at')
            ->max('ends_at');

        $startsAt = ! $isUpgrade && $tailEndsAt !== null && Carbon::parse($tailEndsAt)->isFuture()
            ? Carbon::parse($tailEndsAt)
            : now();

        $endsAt = $startsAt->copy()->addMonthsNoOverflow($months);

        return ClubMembership::create([
            'user_id' => $payment->user_id,
            'payment_id' => $payment->id,
            'tier_code' => $tier,
            'term_months' => $months,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'grace_until' => $endsAt->copy()->addDays($graceDays),
            'grace_days' => $graceDays,
            'source' => ClubMembership::SOURCE_PAYMENT,
        ]);
    }

    /** Ручная выдача периода (репетиция, компенсация) — без платежа. */
    public function grantManualPeriod(
        User $user,
        int $months,
        string $source = ClubMembership::SOURCE_MANUAL,
        MembershipTier $tier = MembershipTier::Club,
    ): ClubMembership {
        $graceDays = max(0, (int) config('membership.club.grace_days', 3));
        $startsAt = now();
        $endsAt = $startsAt->copy()->addMonthsNoOverflow(max(1, $months));

        return ClubMembership::create([
            'user_id' => $user->id,
            'payment_id' => null,
            'tier_code' => $tier,
            'term_months' => max(1, $months),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'grace_until' => $endsAt->copy()->addDays($graceDays),
            'grace_days' => $graceDays,
            'source' => $source,
        ]);
    }

    public function activeFor(User $user): ?ClubMembership
    {
        return ClubMembership::query()
            ->where('user_id', $user->id)
            ->active()
            ->orderByDesc('ends_at')
            ->first();
    }

    public function hasActive(User $user): bool
    {
        return $this->activeFor($user) !== null;
    }

    public function activeTierFor(User $user): ?MembershipTier
    {
        return $this->entitlement->storedActiveTierFor($user);
    }

    /**
     * «Не продлевать». Право НЕ снимается — гасим только намерение продлить,
     * на всех действующих периодах человека. Доступ живёт до ends_at.
     */
    public function cancelRenewal(User $user): ?ClubMembership
    {
        $active = $this->activeFor($user);
        if (! $active instanceof ClubMembership) {
            return null;
        }

        ClubMembership::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereNull('renewal_cancelled_at')
            ->update(['renewal_cancelled_at' => now()]);

        return $active->fresh();
    }

    /** Передумал — возвращаем продление. */
    public function resumeRenewal(User $user): ?ClubMembership
    {
        $active = $this->activeFor($user);
        if (! $active instanceof ClubMembership) {
            return null;
        }

        ClubMembership::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereNotNull('renewal_cancelled_at')
            ->update(['renewal_cancelled_at' => null]);

        return $active->fresh();
    }

    /**
     * Группы, которые демону РАЗРЕШЕНО отцеплять: привязанные к клубному курсу
     * и ни к какому другому. Инвариант 3 живёт здесь.
     *
     * @return array{allowed: list<int>, refused: list<int>}
     */
    public function detachableClubGroupIds(): array
    {
        $course = $this->clubCourse();
        if (! $course instanceof Course) {
            return ['allowed' => [], 'refused' => []];
        }

        $clubGroupIds = $course->groups()->pluck('groups.id')->map(fn ($id) => (int) $id)->all();
        if ($clubGroupIds === []) {
            return ['allowed' => [], 'refused' => []];
        }

        // Сколько курсов держит каждую из этих групп. Ровно 1 (сам клуб) —
        // группа клубная. Больше — общая с обычным курсом: не трогаем.
        $courseCounts = DB::table('course_group')
            ->whereIn('group_id', $clubGroupIds)
            ->select('group_id', DB::raw('COUNT(DISTINCT course_id) as c'))
            ->groupBy('group_id')
            ->pluck('c', 'group_id');

        $allowed = [];
        $refused = [];
        foreach ($clubGroupIds as $groupId) {
            if ((int) ($courseCounts[$groupId] ?? 0) === 1) {
                $allowed[] = $groupId;
            } else {
                $refused[] = $groupId;
            }
        }

        return ['allowed' => $allowed, 'refused' => $refused];
    }

    /**
     * Снять истёкшие периоды.
     *
     * @return array{checked:int, revoked:int, detached:int, superseded:int, refused_groups:list<int>, rows:list<array<string,mixed>>}
     */
    public function expireDue(bool $apply, ?int $limit = null): array
    {
        $query = ClubMembership::query()->due()->with('user')->orderBy('grace_until');
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }
        $memberships = $query->get();

        $groups = $this->detachableClubGroupIds();
        if ($groups['refused'] !== []) {
            Log::error('club:expire — отказ отцеплять группы, привязанные не только к клубному курсу', [
                'group_ids' => $groups['refused'],
            ]);
        }

        $report = [
            'checked' => $memberships->count(),
            'revoked' => 0,
            'detached' => 0,
            'superseded' => 0,
            'refused_groups' => $groups['refused'],
            'rows' => [],
        ];

        foreach ($memberships as $membership) {
            $user = $membership->user;
            if (! $user instanceof User) {
                // Пользователь удалён — период снимаем, отцеплять нечего.
                if ($apply) {
                    $membership->update([
                        'revoked_at' => now(),
                        'revoke_reason' => ClubMembership::REVOKE_LAPSED,
                    ]);
                }
                $report['revoked']++;
                $report['rows'][] = ['membership_id' => $membership->id, 'user_id' => null, 'action' => 'revoked_orphan'];

                continue;
            }

            // Есть ещё живой период (продлился) — снимаем только эту строку.
            $covered = ClubMembership::query()
                ->where('user_id', $user->id)
                ->whereKeyNot($membership->id)
                ->active()
                ->exists();

            $action = $covered ? 'superseded' : 'lapsed';
            $detached = [];

            if (! $covered && $groups['allowed'] !== []) {
                $detached = $groups['allowed'];
                if ($apply) {
                    $user->groups()->detach($detached);
                }
            }

            if ($apply) {
                $membership->update([
                    'revoked_at' => now(),
                    'revoke_reason' => $covered
                        ? ClubMembership::REVOKE_SUPERSEDED
                        : ClubMembership::REVOKE_LAPSED,
                ]);
            }

            $report['revoked']++;
            $covered ? $report['superseded']++ : null;
            if ($detached !== []) {
                $report['detached']++;
            }

            $report['rows'][] = [
                'membership_id' => $membership->id,
                'user_id' => $user->id,
                'email' => $user->email,
                'ends_at' => optional($membership->ends_at)->toDateString(),
                'action' => $action,
                'detached_groups' => $detached,
            ];

            if ($apply) {
                Log::info('club:expire — период снят', [
                    'membership_id' => $membership->id,
                    'user_id' => $user->id,
                    'action' => $action,
                    'detached_groups' => $detached,
                ]);
                if ($action === 'lapsed') {
                    $this->funnel->recordLapse(
                        $user,
                        $membership->tier_code?->value ?? MembershipTier::Club->value,
                        (int) ($membership->term_months ?? 1),
                        (int) $membership->id,
                    );
                }
            }
        }

        return $report;
    }
}
